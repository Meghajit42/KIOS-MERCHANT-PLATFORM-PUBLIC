<?php
declare(strict_types=1);
require 'C:/KiosB0/app/bootstrap.php';
use Kios\Infrastructure\{MigrationRunner,ShopOperations,StaffPasswords};
use Kios\MerchantFoundation\{AuthenticatedUserProvider};
use Kios\MerchantOperations\{TenantFinance,TenantRestaurant,VirtualPrintWorker};
use Kios\Domain\{CheckoutIntent,OrderType};
$result=['passed'=>0,'failed'=>0,'cases'=>[]];
function fid(int $n):string{return '00000000-0000-4000-8000-'.str_pad((string)$n,12,'0',STR_PAD_LEFT);}
function fdb():PDO{return new PDO('mysql:host=127.0.0.1;port=33081;dbname=kios_development','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);}
function fauth(int $n,?Closure $hook=null):AuthenticatedUserProvider{return new class(fid($n),$hook) implements AuthenticatedUserProvider {
    private int $calls=0;
    public function __construct(private string $id,private ?Closure $hook){}
    public function currentUserId():?string{if($this->hook)($this->hook)(++$this->calls);return $this->id;}
};}
function fc(bool $ok):void{if(!$ok)throw new RuntimeException('assertion');}
function fdeny(callable $work):void{try{$work();}catch(DomainException|InvalidArgumentException $expected){return;}throw new RuntimeException('expected denial');}
function fdbdeny(callable $work,string $state):void{try{$work();}catch(PDOException $error){fc((string)$error->getCode()===$state);return;}throw new RuntimeException('expected database constraint');}
function fcase(string $name,callable $work):void{global $result;try{$work();$result['passed']++;$result['cases'][$name]='PASS';}catch(Throwable $e){$result['failed']++;$result['cases'][$name]=get_class($e).':'.$e->getCode().':'.$e->getMessage();}}
function fdig(PDO $db):array {
    $out=[];foreach($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t){
        $rows=$db->query('SELECT * FROM `'.$t.'`')->fetchAll(PDO::FETCH_ASSOC);
        $rows=array_map(fn($r)=>json_encode(array_map(fn($v)=>is_string($v)?['base64'=>base64_encode($v)]:$v,$r),JSON_THROW_ON_ERROR),$rows);sort($rows);
        $out[$t]=['count'=>count($rows),'digest'=>hash('sha256',implode("\n",$rows))];
    }ksort($out);return $out;
}
function fcommand(array $command):void{$p=proc_open($command,[0=>['pipe','r'],1=>['file','C:/m01f-command.out','a'],2=>['file','C:/m01f-command.err','a']],$pipes);if(!is_resource($p))throw new RuntimeException('command');fclose($pipes[0]);if(proc_close($p)!==0)throw new RuntimeException('command exit');}
$db=fdb();
fcase('017 detects unledgered CREATE and restores all populated binary-safe records',function()use(&$db){
    $before=fdig($db);
    fcommand(['C:/Mapped/Tools/mariadb/bin/mysqldump.exe','--no-defaults','--host=127.0.0.1','--port=33081','--user=root','--single-transaction','--hex-blob','--result-file=C:/m01f.sql','--databases','kios_development']);
    $db->exec(file_get_contents('C:/KiosB0/database/migrations/017_merchant_financial_grants.sql'));
    fdbdeny(fn()=>(new MigrationRunner($db))->apply('C:/KiosB0/database/migrations'),'42S01');
    $partial=fdig($db);foreach($before as $t=>$v)fc($partial[$t]===$v);
    fc((int)$db->query("SELECT COUNT(*) FROM schema_migrations WHERE migration='017_merchant_financial_grants.sql'")->fetchColumn()===0);
    $db=null;$root=new PDO('mysql:host=127.0.0.1;port=33081','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$root->exec('DROP DATABASE kios_development');
    fcommand(['C:/Mapped/Tools/mariadb/bin/mysql.exe','--no-defaults','--host=127.0.0.1','--port=33081','--user=root','--execute=source C:/m01f.sql']);
    $db=fdb();fc(fdig($db)===$before);
    fc((new MigrationRunner($db))->apply('C:/KiosB0/database/migrations')===['017_merchant_financial_grants.sql']);
    fc((new MigrationRunner($db))->apply('C:/KiosB0/database/migrations')===[]);
    $after=fdig($db);foreach($before as $t=>$v)if($t!=='schema_migrations')fc($after[$t]===$v);
    file_put_contents('C:/Mapped/Results/m01f-recovery.json',json_encode(['status'=>'PASS','before'=>$before,'after'=>$after,'backupSha256'=>hash_file('sha256','C:/m01f.sql')],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
});
if($result['failed']){file_put_contents('C:/Mapped/Results/m01f-result.json',json_encode($result));exit(1);}
$legacy=[];foreach(['synthetic-a1','synthetic-a2','synthetic-b1'] as $code){$q=$db->prepare('SELECT outlet_id FROM outlets WHERE outlet_code=?');$q->execute([$code]);$legacy[$code]=(int)$q->fetchColumn();}
foreach($legacy as $id)$db->prepare('INSERT IGNORE INTO shop_operations(outlet_id) VALUES(?)')->execute([$id]);
$instant=new DateTimeImmutable('now',new DateTimeZone('Asia/Kolkata'));$clock=static fn()=>$instant;$date=$instant->format('Y-m-d');
$owner=new TenantFinance($db,fauth(101),$clock);$cashier=new TenantFinance($db,fauth(102),$clock);$admin=new TenantFinance($db,fauth(104),$clock);$other=new TenantFinance($db,fauth(107),$clock);
$scope=[fid(1),fid(11)];
fcase('merchant owner has no implicit financial grant',fn()=>fdeny(fn()=>$owner->preview(...$scope)));
$grant=function(int $user,int $merchant,int $outlet,int $id,string $cap)use($db):void{$db->prepare('INSERT INTO merchant_financial_grants VALUES(?,?,?,?,?,1)')->execute([fid($user),fid($merchant),fid($outlet),$id,$cap]);};
foreach([[101,1,11,$legacy['synthetic-a1']],[104,1,11,$legacy['synthetic-a1']],[107,2,21,$legacy['synthetic-b1']],[105,1,11,$legacy['synthetic-a1']]] as [$u,$m,$o,$l])foreach(['read_reconciliation','close_day'] as $cap)$grant($u,$m,$o,$l,$cap);
$grant(102,1,11,$legacy['synthetic-a1'],'read_reconciliation');
// Record exact original financial rows before appending M01F-owned synthetic fixtures.
$protected=[];foreach(['orders'=>'order_id','order_items'=>'order_item_id','payment_confirmations'=>'payment_confirmation_id','shop_day_closings'=>'closing_id','print_jobs'=>'print_job_id','order_status_history'=>'history_id'] as $table=>$key){
    $columns=$db->query('SHOW COLUMNS FROM `'.$table.'`')->fetchAll(PDO::FETCH_COLUMN);if(!in_array($key,$columns,true)){$key=$columns[0];}
    $max=(int)$db->query('SELECT COALESCE(MAX(`'.$key.'`),0) FROM `'.$table.'`')->fetchColumn();
    $protected[$table]=['key'=>$key,'max'=>$max,'rows'=>$db->query('SELECT * FROM `'.$table.'` WHERE `'.$key.'`<='.$max.' ORDER BY `'.$key.'`')->fetchAll(PDO::FETCH_ASSOC)];
}
$restaurant=new TenantRestaurant($db,fauth(101));$orders=[];
$a2=$legacy['synthetic-a2'];$stationA2=(int)$db->query("SELECT station_id FROM preparation_stations WHERE outlet_id=$a2 AND code='SWEETS'")->fetchColumn();
foreach(['cashier_receipt','station_kot'] as $role){
    $db->prepare("INSERT INTO printers(outlet_id,printer_code,name,role,adapter_type,enabled) VALUES(?,?,?,?,'virtual',1)")->execute([$a2,'m01f-'.$role,'Synthetic M01F virtual printer',$role]);
    $printer=(int)$db->lastInsertId();if($role==='station_kot')$db->prepare('INSERT INTO printer_station_routes(outlet_id,station_id,printer_id) VALUES(?,?,?)')->execute([$a2,$stationA2,$printer]);
}
foreach([[fid(11),'synthetic-a1'],[fid(12),'synthetic-a2']] as [$outlet,$code]){
    $p=$restaurant->catalogue(fid(1),$outlet,'sweets')[0];
    $order=$restaurant->checkout(fid(1),$outlet,[['productId'=>$p['productId'],'variantId'=>$p['variants'][0]['variantId'],'quantity'=>1]],OrderType::Takeaway,CheckoutIntent::generateKey(),str_repeat('f',24));
    $q=$db->prepare('SELECT order_id FROM orders WHERE public_id=?');$q->execute([$order->id]);$orders[$code]=(int)$q->fetchColumn();
}
putenv('KIOS_SHOP_OPERATIONS_ENABLED=1');
fcase('cashier reconciliation matches unchanged legacy calculations and reads cause no sweep mutations',function()use($db,$cashier,$scope,$legacy,$clock){$before=fdig($db);$preview=$cashier->preview(...$scope);fc($preview['summary']===(new ShopOperations($db,$clock))->summary($legacy['synthetic-a1']));fc(fdig($db)===$before);});
fcase('foreign merchant financial preview and history are denied',function()use($owner){fdeny(fn()=>$owner->preview(fid(2),fid(21)));fdeny(fn()=>$owner->history(fid(2),fid(21)));});
fcase('outlet membership cannot switch to another outlet of the same merchant',function()use($admin,$date){fdeny(fn()=>$admin->preview(fid(1),fid(12)));fdeny(fn()=>$admin->endDay(fid(1),fid(12),$date,1,bin2hex(random_bytes(16)),[]));});
fcase('owner also needs a separate grant for its second outlet',function()use($owner){fdeny(fn()=>$owner->preview(fid(1),fid(12)));});
foreach(['read_reconciliation','close_day'] as $cap)$grant(101,1,12,$legacy['synthetic-a2'],$cap);
fcase('kitchen cannot use financial grants as role escalation',function()use($db,$clock,$scope,$date){$k=new TenantFinance($db,fauth(105),$clock);fdeny(fn()=>$k->preview(...$scope));fdeny(fn()=>$k->endDay(...[...$scope,$date,1,bin2hex(random_bytes(16)),[]]));});
fcase('platform administrator has no merchant financial fallback',function()use($db,$clock,$scope){fdeny(fn()=>(new TenantFinance($db,fauth(100),$clock))->preview(...$scope));});
fcase('cashier cannot close even when an explicit close grant exists',function()use($cashier,$scope,$date,$grant,$legacy){$grant(102,1,11,$legacy['synthetic-a1'],'close_day');fdeny(fn()=>$cashier->endDay(...[...$scope,$date,1,bin2hex(random_bytes(16)),[]]));});
fcase('cross-merchant close cannot alter financial tables',function()use($db,$owner,$date){$before=fdig($db);fdeny(fn()=>$owner->endDay(fid(2),fid(21),$date,1,bin2hex(random_bytes(16)),[]));fc(fdig($db)===$before);});
fcase('outstanding acknowledgement remains mandatory and failure is atomic',function()use($db,$owner,$scope,$date){$v=$owner->preview(...$scope)['state']['settings']['version'];$before=fdig($db);fdeny(fn()=>$owner->endDay(...[...$scope,$date,(int)$v,bin2hex(random_bytes(16)),[]]));fc(fdig($db)===$before);});
fcase('disabled lifecycle and stale business dates reject closing',function()use($owner,$scope,$date){putenv('KIOS_SHOP_OPERATIONS_ENABLED=0');try{fdeny(fn()=>$owner->endDay(...[...$scope,$date,1,bin2hex(random_bytes(16)),[]]));}finally{putenv('KIOS_SHOP_OPERATIONS_ENABLED=1');}fdeny(fn()=>$owner->endDay(...[...$scope,'2001-01-01',1,bin2hex(random_bytes(16)),[]]));});
fcase('revoked explicit financial permission is rechecked inside the transaction',function()use($db,$clock,$legacy,$scope){$second=fdb();$hook=function(int $n)use($second){if($n===2)$second->prepare("UPDATE merchant_financial_grants SET active=0 WHERE user_id=? AND merchant_outlet_id=? AND capability='read_reconciliation'")->execute([fid(101),fid(11)]);};try{fdeny(fn()=>(new TenantFinance($db,fauth(101,$hook),$clock))->preview(...$scope));}finally{$second->prepare('UPDATE merchant_financial_grants SET active=1 WHERE user_id=?')->execute([fid(101)]);}});
fcase('membership revocation after initial resolution is observed',function()use($db,$clock,$scope){$second=fdb();$hook=function(int $n)use($second){if($n===2)$second->prepare('UPDATE merchant_memberships SET active=0 WHERE user_id=?')->execute([fid(101)]);};try{fdeny(fn()=>(new TenantFinance($db,fauth(101,$hook),$clock))->preview(...$scope));}finally{$second->prepare('UPDATE merchant_memberships SET active=1 WHERE user_id=?')->execute([fid(101)]);}});
fcase('caller owned stale transaction is rejected without committing it',function()use($db,$owner,$scope){$db->beginTransaction();try{try{$owner->preview(...$scope);}catch(LogicException $expected){fc($db->inTransaction());return;}throw new RuntimeException('caller transaction accepted');}finally{$db->rollBack();}});
fcase('audit insertion failure rolls back closing snapshot settings and legacy audit',function()use($db,$owner,$scope,$date){$v=(int)$owner->preview(...$scope)['state']['settings']['version'];$before=fdig($db);$db->exec("CREATE TRIGGER m01f_reject_audit BEFORE INSERT ON merchant_operation_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='controlled fixture audit failure'");try{fdbdeny(fn()=>$owner->endDay(...[...$scope,$date,$v,bin2hex(random_bytes(16)),['acknowledge'=>'1','acknowledgement'=>'Synthetic outstanding plan']]),'45000');fc(fdig($db)===$before);}finally{$db->exec('DROP TRIGGER m01f_reject_audit');}});
fcase('cross-midnight closing rolls back instead of saving mismatched dates',function()use($db,$scope,$date){$calls=0;$first=new DateTimeImmutable($date.' 23:59:59',new DateTimeZone('Asia/Kolkata'));$clock=static function()use(&$calls,$first){return ++$calls===1?$first:$first->modify('+2 seconds');};$service=new TenantFinance($db,fauth(101),$clock);$before=fdig($db);$v=(int)$db->query('SELECT version FROM shop_operations WHERE outlet_id=(SELECT outlet_id FROM outlets WHERE outlet_code=\'synthetic-a1\')')->fetchColumn();fdeny(fn()=>$service->endDay(...[...$scope,$date,$v,bin2hex(random_bytes(16)),['acknowledge'=>'1','acknowledgement'=>'Synthetic boundary plan']]));fc(fdig($db)===$before);});
$request=bin2hex(random_bytes(16));$version=(int)$owner->preview(...$scope)['state']['settings']['version'];$note=['acknowledge'=>'1','acknowledgement'=>'Synthetic outstanding orders remain recoverable'];$closing=null;
fcase('authorized owner close preserves totals snapshots and records canonical actor',function()use($db,$owner,$scope,$date,$request,$version,$note,$legacy,$clock,&$closing){$expected=(new ShopOperations($db,$clock))->summary($legacy['synthetic-a1']);$closing=$owner->endDay(...[...$scope,$date,$version,$request,$note]);$snapshot=$closing['summary'];unset($snapshot['actor'],$snapshot['acknowledgement']);fc($snapshot===$expected);fc($closing['summary']['actor']===fid(101));$q=$db->prepare('SELECT actor_ref FROM shop_operation_events WHERE outlet_id=? AND request_id=?');$q->execute([$legacy['synthetic-a1'],$request]);fc($q->fetchColumn()===fid(101));$q=$db->prepare("SELECT actor_user_id FROM merchant_operation_events WHERE operation='financial-day-closed' AND resource_id=?");$q->execute([$closing['closingId']]);fc($q->fetchColumn()===fid(101));fc(!$owner->preview(...$scope)['state']['open']);});
fcase('same close retry is idempotent with no extra financial audit',function()use($db,$owner,$scope,$date,$request,$version,$note,$closing){$before=fdig($db);$r=$owner->endDay(...[...$scope,$date,$version,$request,$note]);fc($r['duplicate']&&$r['closingId']===$closing['closingId']);fc(fdig($db)===$before);});
fcase('changed payload reused request and stale version are rejected',function()use($owner,$scope,$date,$request,$version,$note){fdeny(fn()=>$owner->endDay(...[...$scope,$date,$version,$request,['acknowledge'=>'1','acknowledgement'=>'Changed synthetic plan']]));fdeny(fn()=>$owner->endDay(...[...$scope,$date,$version,bin2hex(random_bytes(16)),$note]));});
fcase('another authorized administrator cannot replay the original actor request',function()use($admin,$scope,$date,$request,$version,$note){fdeny(fn()=>$admin->endDay(...[...$scope,$date,$version,$request,$note]));});
fcase('financial grant constraints reject cross-merchant mappings and unsupported capability',function()use($db,$legacy){fdbdeny(fn()=>$db->prepare("INSERT INTO merchant_financial_grants VALUES(?,?,?,?,'read_reconciliation',1)")->execute([fid(101),fid(2),fid(11),$legacy['synthetic-a1']]),'23000');fdbdeny(fn()=>$db->prepare("INSERT INTO merchant_financial_grants VALUES(?,?,?,?,'manage_platform',1)")->execute([fid(101),fid(1),fid(11),$legacy['synthetic-a1']]),'23000');});
fcase('valid foreign closing identifier never returns another outlet snapshot',function()use($db,$owner,$other,$cashier,$scope,$date,$note,$closing){$v=(int)$other->preview(fid(2),fid(21))['state']['settings']['version'];$b=$other->endDay(fid(2),fid(21),$date,$v,bin2hex(random_bytes(16)),$note);fdeny(fn()=>$owner->closing(...[...$scope,$b['closingId']]));fdeny(fn()=>$other->closing(fid(2),fid(21),$closing['closingId']));fc((int)$cashier->closing(...[...$scope,$closing['closingId']])['closing_id']===$closing['closingId']);$history=$owner->history(...$scope);fc(!in_array($b['closingId'],array_column($history['closings'],'closing_id')));});
fcase('closing sees payment committed while waiting for the shared outlet lock',function()use($db,$owner,$date,$note,$legacy,$orders,$clock){
    mkdir('C:/M01FWriter');$p=proc_open([PHP_BINARY,'C:/KiosB0/tests/tools/m01f-financial-writer.php',(string)$orders['synthetic-a2']],[0=>['pipe','r'],1=>['file','C:/m01f-writer.out','a'],2=>['file','C:/m01f-writer.err','a']],$pipes);fclose($pipes[0]);
    $deadline=microtime(true)+15;while(!is_file('C:/M01FWriter/ready')){if(microtime(true)>$deadline)throw new RuntimeException('writer not ready');usleep(20000);}
    $v=(int)$db->query('SELECT version FROM shop_operations WHERE outlet_id='.$legacy['synthetic-a2'])->fetchColumn();
    $hook=function(int $n)use($db){if($n===2){$db->query('SELECT COUNT(*) FROM payment_confirmations')->fetchColumn();file_put_contents('C:/M01FWriter/continue','1');}};
    $service=new TenantFinance($db,fauth(101,$hook),$clock);
    try{$r=$service->endDay(fid(1),fid(12),$date,$v,bin2hex(random_bytes(16)),$note);fc(proc_close($p)===0);$q=$db->prepare('SELECT amount_paise FROM payment_confirmations WHERE order_id=?');$q->execute([$orders['synthetic-a2']]);fc($r['summary']['paymentTotalPaise']===(int)$q->fetchColumn());fc(is_file('C:/M01FWriter/done'));}finally{if(is_resource($p)){proc_terminate($p);proc_close($p);}}
});
fcase('all pre-existing order payment closing and print snapshots remain unchanged',function()use($db,$protected){foreach($protected as $t=>$v)fc($db->query('SELECT * FROM `'.$t.'` WHERE `'.$v['key'].'`<='.$v['max'].' ORDER BY `'.$v['key'].'`')->fetchAll(PDO::FETCH_ASSOC)===$v['rows']);});
// A new explicit service principal has no financial role, even if a grant is misprovisioned.
$service=fid(950);$actor=fid(951);$secret=bin2hex(random_bytes(32));$db->prepare('INSERT INTO merchant_staff VALUES(?,?,1)')->execute([$actor,'Synthetic M01F service']);
$db->prepare("INSERT INTO merchant_worker_grants VALUES(?,?,?,?,?,?,'virtual_print',1,?)")->execute([$service,$actor,fid(1),fid(12),$legacy['synthetic-a2'],hash('sha256',$secret),time()+300]);
$grant(951,1,12,$legacy['synthetic-a2'],'read_reconciliation');$grant(951,1,12,$legacy['synthetic-a2'],'close_day');
fcase('virtual worker cannot become a financial administrator',function()use($db,$clock,$date){$f=new TenantFinance($db,fauth(951),$clock);fdeny(fn()=>$f->preview(fid(1),fid(12)));fdeny(fn()=>$f->endDay(fid(1),fid(12),$date,1,bin2hex(random_bytes(16)),[]));});
fcase('worker grant revocation prevents virtual delivery without administrative fallback',function()use($db,$service,$secret,$legacy){$job=(int)$db->query('SELECT print_job_id FROM print_jobs WHERE outlet_id='.$legacy['synthetic-a2'].' LIMIT 1')->fetchColumn();fc($job>0);$worker=new VirtualPrintWorker($db,$service,$secret);fc($worker->deliver(fid(1),fid(12),$job)->value==='printed');$db->prepare('UPDATE merchant_worker_grants SET active=0 WHERE service_id=?')->execute([$service]);fdeny(fn()=>$worker->deliver(fid(1),fid(12),$job));fdeny(fn()=>$worker->deliver(fid(2),fid(21),$job));});
// Browser credentials remain in guest-private storage; never exported with evidence.
$browser=json_decode(file_get_contents('C:/M01EBrowserCredentials.json'),true,64,JSON_THROW_ON_ERROR);
$password=bin2hex(random_bytes(16));$account=bin2hex(random_bytes(16));$db->prepare("INSERT INTO staff_accounts(account_id,outlet_id,realm,username,display_name,role,password_hash,enabled,must_change,auth_version,created_at,updated_at) VALUES(?,?,'staff','m01f-cashier','Synthetic finance cashier','cashier',?,1,0,1,?,?)")->execute([$account,$legacy['synthetic-a1'],StaffPasswords::hash($password),time(),time()]);$db->prepare('INSERT INTO merchant_auth_links VALUES(?,?,?,1)')->execute([$account,fid(102),$legacy['synthetic-a1']]);
$browser['cashier']=['username'=>'m01f-cashier','password'=>$password,'outlet'=>$legacy['synthetic-a1']];$browser['closingId']=$closing['closingId'];
file_put_contents('C:/M01FBrowserCredentials.json',json_encode($browser,JSON_THROW_ON_ERROR));
file_put_contents('C:/Mapped/Results/m01f-result.json',json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
$env=[];foreach(['SystemRoot','WINDIR','PATH','PATHEXT','TEMP','TMP','COMSPEC','PHPRC','PHP_INI_SCAN_DIR'] as $n){$v=getenv($n);if(is_string($v))$env[$n]=$v;}$env+=['APP_ENV'=>'local','KIOS_MERCHANT_DEV_ENABLED'=>'1','KIOS_MERCHANT_AUTH_RATE_KEY'=>bin2hex(random_bytes(32)),'KIOS_SHOP_OPERATIONS_ENABLED'=>'1'];
$web=proc_open([PHP_BINARY,'-S','127.0.0.1:8089','-t','C:/KiosB0/public','C:/KiosB0/tests/tools/m01e-router.php'],[0=>['pipe','r'],1=>['file','C:/Mapped/Results/m01f-web.stdout.txt','a'],2=>['file','C:/Mapped/Results/m01f-web.stderr.txt','a']],$pipes,'C:/KiosB0',$env);fclose($pipes[0]);usleep(400000);
$browserCode=1;try{$p=proc_open(['C:/Mapped/Tools/node/node.exe','C:/KiosB0/tests/tools/m01f-browser.js'],[0=>['pipe','r'],1=>['file','C:/Mapped/Results/m01f-browser.stdout.txt','a'],2=>['file','C:/Mapped/Results/m01f-browser.stderr.txt','a']],$pipes,'C:/KiosB0',$env);fclose($pipes[0]);$browserCode=proc_close($p);}finally{proc_terminate($web);proc_close($web);}
fcase('browser closing preserves every pre-existing business snapshot',function()use($db,$protected){foreach($protected as $t=>$v)fc($db->query('SELECT * FROM `'.$t.'` WHERE `'.$v['key'].'`<='.$v['max'].' ORDER BY `'.$v['key'].'`')->fetchAll(PDO::FETCH_ASSOC)===$v['rows']);});
file_put_contents('C:/Mapped/Results/m01f-result.json',json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
exit($result['failed']===0&&$browserCode===0?0:1);
