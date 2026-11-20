<?php
declare(strict_types=1);
require_once __DIR__.'/PersistenceTest.php';
use Kios\Infrastructure\{ShopOperations,OrderRepository,PreparationWorkflowService};
use Kios\Application\StaffOperationsView;
use Kios\Domain\{OrderType,PaymentMethod,PreparationTaskStatus};

function shopFixture():array {
 [$db]=kiosTestPersistence();$sql=(string)file_get_contents(dirname(__DIR__).'/database/migrations/012_shop_operations.sql');
 $sql=str_replace('BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY','INTEGER PRIMARY KEY AUTOINCREMENT',$sql);
 $sql=str_replace(['BIGINT UNSIGNED',' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'],['INTEGER',''],$sql);$sql=preg_replace('/UNIQUE KEY [a-z_]+/','UNIQUE',$sql);$db->exec($sql);$db->exec('INSERT INTO shop_operations(outlet_id) VALUES(1)');return [$db];
}
function shopCheck(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
function shopDenied(callable $work):void {$denied=false;try{$work();}catch(DomainException){$denied=true;}shopCheck($denied,'Expected shop action/checkout to be refused');}
function shopSetting(ShopOperations $ops,string $action,array $fields=[]):array{return $ops->act(1,$action,(int)$ops->settings(1)['version'],bin2hex(random_bytes(16)),$fields);}
function shopClockScope(callable $test):void {$old=getenv('KIOS_SHOP_OPERATIONS_ENABLED');putenv('KIOS_SHOP_OPERATIONS_ENABLED=1');try{$test();}finally{putenv($old===false?'KIOS_SHOP_OPERATIONS_ENABLED':'KIOS_SHOP_OPERATIONS_ENABLED='.$old);}}
function shopConfigure(ShopOperations $ops,string $opening='07:00',string $closing='22:00'):void {shopSetting($ops,'configure',['opening_time'=>$opening,'closing_time'=>$closing,'unpaid_minutes'=>'15','ready_warning_minutes'=>'30','exception_minutes'=>'120','collected_seconds'=>'180','aging_minutes'=>'5']);}
function shopMakeReady(PDO $db,OrderRepository $repo,string $token):void {
 $order=$repo->confirmPaymentByInternalToken($token,PaymentMethod::Cash);$q=$db->prepare('SELECT pt.preparation_task_id,ps.code FROM preparation_tasks pt JOIN preparation_stations ps ON ps.station_id=pt.station_id WHERE pt.order_id=(SELECT order_id FROM orders WHERE token=?)');$q->execute([$token]);
 foreach($q->fetchAll() as $t)foreach([PreparationTaskStatus::Queued,PreparationTaskStatus::Preparing] as $status)(new PreparationWorkflowService($db))->advanceTask((int)$t['preparation_task_id'],1,$t['code'],$status);
}
return [
 'public display reports authoritative shop state without settings' => static function():void {shopClockScope(function(){
  [$db]=shopFixture();$ops=new ShopOperations($db);$service=new \Kios\Infrastructure\PublicTokenDisplayService($db);
  $closed=$service->snapshot();shopCheck(($closed['shopOpen']??null)===false,'Closed state missing from public snapshot');
  shopConfigure($ops,'00:00','23:59');shopSetting($ops,'open');$open=$service->snapshot();shopCheck($open['shopOpen']===$ops->state(1)['open'],'Public state differs from authoritative server state');
  if ($ops->now()->format('H:i') !== '23:59') shopCheck($open['shopOpen']===true,'Open state missing from public snapshot');
  shopCheck(array_keys($open)===['outlet','tokens','shopOpen'],'Public snapshot exposes unexpected settings');
  shopSetting($ops,'close');shopCheck($service->snapshot()['shopOpen']===false,'Manual closure absent from public snapshot');
 });},
 'daily pickup sequence restarts safely with unresolved prior-day tokens' => static function():void {shopClockScope(function(){
  [$db]=shopFixture();$now=new DateTimeImmutable('2030-05-01 09:00:00',new DateTimeZone('Asia/Kolkata'));$clock=static function()use(&$now){return $now;};$ops=new ShopOperations($db,$clock);$repo=new OrderRepository($db,businessClock:$clock);shopSetting($ops,'open');$old=$repo->createFromCart(['kaju-barfi'=>['kaju-100g'=>1]],OrderType::Takeaway);$repo->confirmPaymentByInternalToken($old->token,PaymentMethod::Cash);$now=$now->modify('+1 day');shopSetting($ops,'open');$current=$repo->createFromCart(['kaju-barfi'=>['kaju-100g'=>1]],OrderType::Takeaway);shopCheck($old->publicToken()==='G-101'&&$current->publicToken()==='G-101'&&$old->id!==$current->id,'Tokens are not date-scoped');shopCheck($ops->rows(1)[1]['carry_over'],'Prior paid token lost carry-over status');shopCheck($repo->findByPickupTokenForOutlet('G-101',1,'2030-05-01')?->id===$old->id&&$repo->findByPickupTokenForOutlet('G-101',1,'2030-05-02')?->id===$current->id,'Dated receipt lookup crossed business days');shopCheck($repo->findByPickupTokenForOutlet('G-101',1,'2030-02-31')===null,'Invalid receipt date accepted');
 });},
 'closed checkout, manual opening, closure and same-day schedule enforced' => static function():void {shopClockScope(function(){
  [$db]=shopFixture();$now=new DateTimeImmutable('2030-05-01 07:00:00',new DateTimeZone('Asia/Kolkata'));$clock=static function()use(&$now){return $now;};$ops=new ShopOperations($db,$clock);$repo=new OrderRepository($db,businessClock:$clock);
  shopDenied(fn()=>$repo->createFromCart(['kaju-barfi'=>['kaju-100g'=>1]],OrderType::Takeaway));shopCheck((int)$db->query('SELECT COUNT(*) FROM orders')->fetchColumn()===0,'Closed checkout wrote order');
  shopConfigure($ops);shopSetting($ops,'open');shopCheck($ops->state(1)['open'],'07:00 must be supported');$order=$repo->createFromCart(['kaju-barfi'=>['kaju-100g'=>1]],OrderType::Takeaway);shopCheck($order->publicToken()==='G-101','First pickup sequence');
  shopSetting($ops,'close');shopDenied(fn()=>$repo->createFromCart(['kaju-barfi'=>['kaju-100g'=>1]],OrderType::Takeaway));$now=$now->modify('+1 hour');shopCheck(!$ops->state(1)['open'],'Manual close reopened automatically');
  shopSetting($ops,'open');$now=$now->setTime(22,0);shopCheck(!$ops->state(1)['open'],'Scheduled close ignored');$ops->sweep(1);shopCheck((int)$ops->settings(1)['manual_enabled']===0,'Scheduled closure not synchronized');
 });},
 'new business day defaults closed and requests are idempotent and versioned' => static function():void {shopClockScope(function(){
  [$db]=shopFixture();$now=new DateTimeImmutable('2030-05-01 09:00:00',new DateTimeZone('Asia/Kolkata'));$clock=static function()use(&$now){return $now;};$ops=new ShopOperations($db,$clock);$request=str_repeat('b',32);$v=(int)$ops->settings(1)['version'];$ops->act(1,'open',$v,$request);shopCheck($ops->act(1,'open',$v,$request)['duplicate'],'Duplicate opening not idempotent');shopDenied(fn()=>$ops->act(1,'close',$v,str_repeat('c',32)));shopDenied(fn()=>$ops->act(1,'close',$v,$request));
  $now=$now->modify('+1 day');shopCheck(!$ops->state(1)['open'],'New day inherited opening');$ops->sweep(1);shopCheck((int)$db->query("SELECT COUNT(*) FROM shop_operation_events WHERE action='day_boundary_close'")->fetchColumn()===1,'Boundary audit absent');
 });},
 'unpaid expiry is idempotent, backend enforced and never expires paid records' => static function():void {shopClockScope(function(){
  [$db]=shopFixture();$now=new DateTimeImmutable('2030-05-01 09:00:00',new DateTimeZone('Asia/Kolkata'));$clock=static function()use(&$now){return $now;};$ops=new ShopOperations($db,$clock);$repo=new OrderRepository($db,businessClock:$clock);shopSetting($ops,'open');
  $unpaid=$repo->createFromCart(['kaju-barfi'=>['kaju-100g'=>1]],OrderType::Takeaway);$paid=$repo->createFromCart(['kaju-barfi'=>['kaju-100g'=>1]],OrderType::Takeaway);$repo->confirmPaymentByInternalToken($paid->token,PaymentMethod::Cash);$now=$now->modify('+15 minutes');shopDenied(fn()=>$repo->confirmPaymentByInternalToken($unpaid->token,PaymentMethod::Cash));shopCheck($ops->sweep(1)===1&&$ops->sweep(1)===0,'Unpaid expiry non-idempotent');shopCheck($repo->findByInternalToken($unpaid->token)->status->value==='expired','Unpaid expiry not durable');shopCheck($repo->findByInternalToken($paid->token)->status->value==='preparing','Paid order expired');shopCheck((int)$db->query('SELECT COUNT(*) FROM payment_confirmations')->fetchColumn()===1,'Payment duplicated/lost');
 });},
 'ready warnings exceptions collection TTL and daily summary preserve financial history' => static function():void {shopClockScope(function(){
  [$db]=shopFixture();$now=new DateTimeImmutable('2030-05-01 09:00:00',new DateTimeZone('Asia/Kolkata'));$clock=static function()use(&$now){return $now;};$ops=new ShopOperations($db,$clock);$repo=new OrderRepository($db,businessClock:$clock);shopSetting($ops,'open');$order=$repo->createFromCart(['kaju-barfi'=>['kaju-100g'=>1]],OrderType::Takeaway);shopMakeReady($db,$repo,$order->token);
  $utc=$now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');$q=$db->prepare("UPDATE order_status_history SET changed_at=? WHERE status='ready'");$q->execute([$utc]);$db->prepare('UPDATE payment_confirmations SET confirmed_at=?')->execute([$utc]);
  shopCheck(count($ops->publicTokens(1,'Test')['tokens'])===1,'Ready token absent');$now=$now->modify('+30 minutes');shopCheck($ops->rows(1)[0]['ready_warning'],'30 minute warning absent');$now=$now->modify('+90 minutes');shopCheck($ops->rows(1)[0]['bucket']==='exceptions'&&$ops->publicTokens(1,'Test')['tokens']===[],'Overdue not moved to recoverable exceptions');
  shopDenied(fn()=>shopSetting($ops,'end-day'));$request=str_repeat('d',32);$version=(int)$ops->settings(1)['version'];$fields=['acknowledge'=>'1','acknowledgement'=>'Synthetic carry-over retained for authorized collection'];$ops->act(1,'end-day',$version,$request,$fields);shopCheck($ops->act(1,'end-day',$version,$request,$fields)['duplicate'],'Duplicate close created summary');shopCheck((int)$db->query('SELECT COUNT(*) FROM shop_day_closings')->fetchColumn()===1,'Daily summary duplicated');$summary=json_decode($db->query('SELECT summary_json FROM shop_day_closings')->fetchColumn(),true);shopCheck($summary['paymentTotalPaise']===15000&&$summary['todayPaidOrderCount']===1,'Closing totals do not reconcile');shopCheck(!$ops->state(1)['open'],'End day not closed');
  $id=(int)$db->query('SELECT order_id FROM orders')->fetchColumn();$repo->markCollectedById($id,1,'ground_floor');$stamp=$now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');$db->prepare("UPDATE order_status_history SET changed_at=? WHERE status='collected'")->execute([$stamp]);shopSetting($ops,'open');shopCheck($ops->publicTokens(1,'Test')['tokens'][0]['publicStatus']==='COLLECTED','Collected grace period missing');$now=$now->modify('+180 seconds');shopCheck($ops->publicTokens(1,'Test')['tokens']===[],'Collected token retained beyond TTL');shopCheck((int)$db->query('SELECT COUNT(*) FROM payment_confirmations')->fetchColumn()===1,'Collection altered payment');$now=$now->modify('+1 day');shopCheck(!$ops->state(1)['open'],'Next day opened itself');shopSetting($ops,'open');$second=$repo->createFromCart(['kaju-barfi'=>['kaju-100g'=>1]],OrderType::Takeaway);shopCheck($second->publicToken()==='G-101'&&$second->id!==$order->id,'New day token uniqueness/reset failed');
 });},
 'cashier dashboard separates lifecycle queues and keeps warnings searchable' => static function():void {shopClockScope(function(){
  [$db]=shopFixture();$ops=new ShopOperations($db);$repo=new OrderRepository($db);shopConfigure($ops,'00:00','23:59');shopSetting($ops,'open');
  $rowId=static function($order)use($db):int{$query=$db->prepare('SELECT order_id FROM orders WHERE token=?');$query->execute([$order->token]);return (int)$query->fetchColumn();};
  $firstUnpaid=$repo->createFromCart(['kaju-barfi'=>['kaju-100g'=>1]],OrderType::Takeaway);
  $latestUnpaid=$repo->createFromCart(['kaju-barfi'=>['kaju-100g'=>1]],OrderType::Takeaway);
  $draft=$repo->createFromCart(['kaju-barfi'=>['kaju-100g'=>1]],OrderType::Takeaway);
  $db->prepare("UPDATE orders SET status='draft' WHERE order_id=?")->execute([$rowId($draft)]); // Isolated fixture for the persisted-draft display state.
  $cancelled=$repo->createFromCart(['kaju-barfi'=>['kaju-100g'=>1]],OrderType::Takeaway);
  $repo->cancelPendingByInternalToken($cancelled->token,'synthetic-cashier',1);
  $paid=$repo->createFromCart(['kaju-barfi'=>['kaju-100g'=>1]],OrderType::Takeaway);$repo->confirmPaymentByInternalToken($paid->token,PaymentMethod::Cash);
  $db->prepare("UPDATE orders SET status='paid' WHERE order_id=?")->execute([$rowId($paid)]); // Isolated fixture for the brief PAID state.
  $preparing=$repo->createFromCart(['kaju-barfi'=>['kaju-100g'=>1]],OrderType::Takeaway);$repo->confirmPaymentByInternalToken($preparing->token,PaymentMethod::Cash);
  $ready=$repo->createFromCart(['kaju-barfi'=>['kaju-100g'=>1]],OrderType::Takeaway);shopMakeReady($db,$repo,$ready->token);
  $oldReady=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->modify('-45 minutes')->format('Y-m-d H:i:s');
  $db->prepare("UPDATE order_status_history SET changed_at=? WHERE order_id=? AND status='ready'")->execute([$oldReady,$rowId($ready)]);
  $collected=$repo->createFromCart(['kaju-barfi'=>['kaju-100g'=>1]],OrderType::Takeaway);shopMakeReady($db,$repo,$collected->token);$repo->markCollectedById($rowId($collected),1,'ground_floor');
  $expired=$repo->createFromCart(['kaju-barfi'=>['kaju-100g'=>1]],OrderType::Takeaway);
  $db->prepare('UPDATE shop_order_acceptance SET unpaid_deadline_epoch=? WHERE order_id=?')->execute([time()-1,$rowId($expired)]);
  shopSetting($ops,'close');
  $paymentCount=(int)$db->query('SELECT COUNT(*) FROM payment_confirmations')->fetchColumn();
  $render=static fn(string $queue,array $get=[]):string=>StaffOperationsView::render($db,1,'cashier','fixture-csrf',['queue'=>$queue,...$get]);
  $awaiting=$render('awaiting');shopCheck(str_contains($awaiting,'Shop CLOSED'),'Cashier desk does not show the closed state');shopCheck(str_contains($awaiting,'aria-current="page"'),'Selected cashier queue is not announced');
  shopCheck(strpos($awaiting,'data-order-id="'.$rowId($latestUnpaid).'"')<strpos($awaiting,'data-order-id="'.$rowId($firstUnpaid).'"'),'Awaiting-payment queue is not newest first');
  shopCheck(str_contains($awaiting,'Payment due')&&str_contains($awaiting,'₹150.00'),'Awaiting-payment card omits payment state or amount');
  shopCheck(str_contains($awaiting,'New · awaiting payment'),'New order state is not clearly labeled');
  shopCheck(str_contains($render('paid'),$paid->publicToken())&&!str_contains($render('paid'),$preparing->publicToken()),'Paid queue is not separated from preparing');
  shopCheck(str_contains($render('preparing'),$preparing->publicToken()),'Preparing order is not visible in its own queue');
  $exceptions=$render('exceptions');shopCheck(str_contains($exceptions,$ready->publicToken())&&str_contains($exceptions,'Ready for too long'),'Overdue-ready warning is missing');
  shopCheck(str_contains($exceptions,$draft->publicToken())&&str_contains($exceptions,'Incomplete draft'),'Unexpected draft is not surfaced for follow-up');
  shopCheck(str_contains($exceptions,$expired->publicToken())&&str_contains($exceptions,'Payment window expired'),'Expired unpaid order is missing or could appear payable');
  $db->prepare('UPDATE orders SET pickup_business_date=? WHERE order_id=?')->execute(['2020-01-01',$rowId($collected)]);
  $history=$render('history');
  shopCheck(str_contains($history,$collected->publicToken()),'Collected order is missing from completed history');
  shopCheck(!str_contains($history,'Review before taking action.'),'Collected carry-over order is shown as needing action');
  shopCheck(!str_contains($render('exceptions'),$collected->publicToken()),'Collected order is shown in needs-attention queue');
  shopCheck(str_contains($render('closed'),$cancelled->publicToken()),'Cancelled order is missing from closed-order history');
  $searched=$render('awaiting',['ops_q'=>$latestUnpaid->publicToken()]);shopCheck(str_contains($searched,'Search results')&&str_contains($searched,$latestUnpaid->publicToken()),'Token search did not find the matching order');
  $groundFloor=StaffOperationsView::render($db,1,'ground_floor','fixture-csrf',['queue'=>'ready']);
  shopCheck(str_contains($groundFloor,'/assets/shop-operations.css')&&!str_contains($groundFloor,'ops-cashier-panel'),'Cashier styling or queue markup leaked into Ground Floor');
  shopCheck((int)$db->query('SELECT COUNT(*) FROM payment_confirmations')->fetchColumn()===$paymentCount,'Rendering cashier queues changed payment records');
  $cashier=(string)file_get_contents(dirname(__DIR__).'/public/cashier.php');$css=(string)file_get_contents(dirname(__DIR__).'/public/assets/cashier-dashboard.css');$js=(string)file_get_contents(dirname(__DIR__).'/public/assets/cashier-dashboard.js');
  shopCheck(str_contains($cashier,'data-confirm-once')&&str_contains($cashier,'cashier-desk-page'),'Cashier payment protection or page scoping is missing');
  shopCheck(str_contains($js,'if (submitted)')&&str_contains($js,'button.disabled = true'),'Payment submit does not prevent a second click');
  shopCheck(str_contains($css,'.cashier-desk-page .ops-queues')&&!str_contains($css,'.station-task {'),'Cashier styles are not role-scoped');
 });},
];
