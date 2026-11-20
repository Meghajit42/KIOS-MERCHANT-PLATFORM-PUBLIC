<?php
declare(strict_types=1);
require_once __DIR__.'/_bootstrap.php';
adminRequireLogin();
use Kios\Infrastructure\{Database,ShopOperations};
if(!ShopOperations::enabled()){http_response_code(503);exit('Shop operations are not enabled.');}
$db=Database::connect();$outlet=(int)$db->query("SELECT outlet_id FROM outlets WHERE outlet_code='demo-outlet' AND active=1")->fetchColumn();$ops=new ShopOperations($db);$error='';
if($_SERVER['REQUEST_METHOD']==='POST')try{
    adminRequireCsrf();$v=adminPost('version');if(!ctype_digit($v))throw new DomainException('Invalid shop version.');
    $action=adminPost('action');$fields=[];
    if($action==='configure')foreach(['opening_time','closing_time','unpaid_minutes','ready_warning_minutes','exception_minutes','collected_seconds','aging_minutes'] as $k)$fields[$k]=adminPost($k);
    if($action==='end-day')$fields=['acknowledge'=>adminPost('acknowledge'),'acknowledgement'=>adminPost('acknowledgement')];
    $result=$ops->act($outlet,$action,(int)$v,adminPost('request_id'),$fields);
    adminFlash(($result['duplicate']??false)?'That shop action was already recorded.':'Shop action saved and audited.');header('Location: /admin/shop-operations.php',true,303);exit;
}catch(Throwable $e){http_response_code($e instanceof DomainException?400:500);$error=adminFail($e);}
$ops->sweep($outlet);$state=$ops->state($outlet);$s=$state['settings'];$summary=$ops->summary($outlet);
if(isset($_GET['closing'])){
    $id=is_string($_GET['closing'])&&ctype_digit($_GET['closing'])?(int)$_GET['closing']:0;
    $q=$db->prepare('SELECT summary_json FROM shop_day_closings WHERE outlet_id=? AND closing_id=?');$q->execute([$outlet,$id]);$json=$q->fetchColumn();if(!is_string($json)){http_response_code(404);exit('Closing summary not found.');}
    header('Content-Type: application/json; charset=utf-8');header('Content-Disposition: attachment; filename="kios-daily-closing-'.$id.'.json"');echo $json;exit;
}
function shopFields(array $s):string{return adminCsrfField().'<input type="hidden" name="version" value="'.adminH($s['version']).'"><input type="hidden" name="request_id" value="'.bin2hex(random_bytes(16)).'">';}
adminStart('Shop operations and daily close');echo '<link rel="stylesheet" href="/assets/shop-operations.css">';
if($error!=='')echo '<p class="admin-flash" role="alert">'.adminH($error).'</p>';
echo '<section class="ops-panel owner-shop-state '.($state['open']?'owner-shop-open':'owner-shop-closed').'"><h2 data-shop-state>SHOP '.($state['open']?'OPEN':'CLOSED').'</h2><p>'.adminH($state['reason']).' · Business date '.adminH($state['businessDate']).'</p><p>Today’s hours: '.adminH($s['opening_time']).'–'.adminH($s['closing_time']).' IST · Next scheduled opening: '.adminH($state['nextOpening']).'</p><p>Closing does not cancel paid orders. Opening applies to this business date and scheduled hours.</p><div class="ops-actions">';
foreach(['open'=>'Open Shop','close'=>'Close Shop'] as $action=>$label)echo '<form method="post">'.shopFields($s).'<button class="admin-button" name="action" value="'.$action.'">'.$label.'</button></form>';
echo '</div></section><section class="ops-panel"><h2>Opening hours and lifecycle</h2><form method="post" class="ops-form">'.shopFields($s).'<input type="hidden" name="action" value="configure">';
foreach(['opening_time'=>'Opening time (IST)','closing_time'=>'Closing time (IST)'] as $key=>$label)echo '<label>'.$label.'<input type="time" name="'.$key.'" value="'.adminH($s[$key]).'" required></label>';
foreach(['unpaid_minutes'=>'Unpaid expiry (minutes)','ready_warning_minutes'=>'Ready warning (minutes)','exception_minutes'=>'Uncollected exception (minutes)','collected_seconds'=>'Collected display (seconds)','aging_minutes'=>'Aging warning (minutes)'] as $key=>$label)echo '<label>'.$label.'<input type="number" name="'.$key.'" value="'.adminH($s[$key]).'" min="'.($key==='collected_seconds'?0:1).'" max="'.($key==='collected_seconds'?600:1440).'" required></label>';
echo '<button class="admin-button">Save hours and timeouts</button></form></section><section class="ops-panel"><h2>Daily closing preview — '.adminH($summary['businessDate']).'</h2><p>Orders today: <strong>'.$summary['orderCount'].'</strong> · Paid orders: '.$summary['todayPaidOrderCount'].' · Unpaid orders: '.$summary['todayUnpaidOrderCount'].'</p><p>Payments confirmed today: <strong>INR '.number_format($summary['paymentTotalPaise']/100,2).'</strong></p><ul>';
foreach($summary['orderStateCounts'] as $status=>$count)echo '<li>'.adminH(strtoupper($status)).': '.$count.'</li>';
foreach($summary['payments'] as $p)echo '<li>'.adminH(strtoupper($p['payment_method'])).': '.$p['count'].' payments · INR '.number_format((int)$p['amount_paise']/100,2).'</li>';
echo '</ul><h3>Outstanding and carry-over orders ('.count($summary['outstanding']).')</h3><ul>';
if ($summary['outstanding'] === []) echo '<li>No outstanding orders.</li>';
foreach($summary['outstanding'] as $o)echo '<li>ID '.$o['orderId'].' · '.adminH($o['businessDate']).' · '.adminH($o['token']).' · '.adminH(strtoupper($o['status'])).' · '.($o['paid']?'PAID':'UNPAID').' · INR '.number_format($o['amountPaise']/100,2).'</li>';
echo '</ul><p>End Day disables new ordering, preserves all order/payment records and records this summary. Outstanding orders remain recoverable. Reopening the same date does not reset token numbering.</p><form method="post" class="owner-end-day">'.shopFields($s).'<input type="hidden" name="action" value="end-day"><label><input type="checkbox" name="acknowledge" value="1"> I acknowledge the outstanding orders listed above; none is refunded, cancelled, disposed or marked collected by End Day.</label><label>Outstanding-order plan / closing note<textarea name="acknowledgement" maxlength="1000" rows="3"></textarea></label><button class="admin-button admin-button-danger">End Day and save closing summary</button></form></section>';
$q=$db->prepare('SELECT closing_id,business_date,closed_epoch FROM shop_day_closings WHERE outlet_id=? ORDER BY closing_id DESC');$q->execute([$outlet]);echo '<section class="ops-panel"><h2>Durable daily summaries</h2><ul>';foreach($q->fetchAll(PDO::FETCH_ASSOC) as $c)echo '<li><a href="?closing='.$c['closing_id'].'">'.adminH($c['business_date']).' · closing #'.$c['closing_id'].'</a></li>';echo '</ul></section>';
$page=max(1,min(10000,(int)($_GET['history_page']??1)));$offset=($page-1)*50;$q=$db->prepare('SELECT event_id,action,actor_ref,occurred_epoch FROM shop_operation_events WHERE outlet_id=? ORDER BY event_id DESC LIMIT 50 OFFSET '.$offset);$q->execute([$outlet]);echo '<section class="ops-panel"><h2>Opening and closure audit</h2><ul>';foreach($q->fetchAll(PDO::FETCH_ASSOC) as $e)echo '<li>#'.$e['event_id'].' · '.adminH(gmdate('Y-m-d H:i:s',(int)$e['occurred_epoch'])).' UTC · '.adminH($e['action']).' · '.adminH($e['actor_ref']).'</li>';echo '</ul><a href="?history_page='.max(1,$page-1).'">Newer</a> · <a href="?history_page='.($page+1).'">Older</a></section>';adminEnd();
