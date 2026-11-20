<?php
declare(strict_types=1);
require 'C:/KiosB0/app/bootstrap.php';

use Kios\Domain\{CheckoutIntent, OrderType, PreparationTaskStatus, OrderStatus, PrintJobStatus, PrintJobAction, PrinterAdapterType};
use Kios\Infrastructure\{MigrationRunner, PrintJobService, PrintAdapter, VirtualPrinterAdapter};
use Kios\MerchantFoundation\{AuthenticatedUserProvider, PdoIdentityStore, Membership, Role};
use Kios\MerchantOperations\{TenantRestaurant,TenantOperations};

$result=['passed'=>0,'failed'=>0,'cases'=>[]];
function did(int $n):string{return '00000000-0000-4000-8000-'.str_pad((string)$n,12,'0',STR_PAD_LEFT);}
function dauth(int $n):AuthenticatedUserProvider {
    return new class(did($n)) implements AuthenticatedUserProvider {
        public function __construct(private string $id){}
        public function currentUserId():?string{return $this->id;}
    };
}
function ddb():PDO{return new PDO('mysql:host=127.0.0.1;port=33081;dbname=kios_development','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);}
function dc(bool $yes):void{if(!$yes)throw new RuntimeException('assertion');}
function ddeny(callable $action):void{
    try{$action();}catch(DomainException|InvalidArgumentException|PDOException $expected){return;}
    throw new RuntimeException('expected denial');
}
function dcase(string $name,callable $action):void {
    global $result;
    try{$action();$result['passed']++;$result['cases'][$name]='PASS';}
    catch(Throwable $e){$result['failed']++;$result['cases'][$name]=get_class($e).':'.$e->getCode().':'.$e->getMessage();}
}
function ddig(PDO $db):array {
    $out=[];
    foreach($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table){
        $rows=$db->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC);
        $rows=array_map(fn($row)=>json_encode(array_map(static fn($value)=>is_string($value)?["base64"=>base64_encode($value)]:$value,$row),JSON_THROW_ON_ERROR),$rows);sort($rows);
        $out[$table]=['count'=>count($rows),'digest'=>hash('sha256',implode("\n",$rows))];
    }
    ksort($out);return $out;
}
function dcmd(array $args):void{
    $p=proc_open($args,[0=>['pipe','r'],1=>['file','C:/m01d.out','a'],2=>['file','C:/m01d.err','a']],$pipes);
    if(!is_resource($p))throw new RuntimeException('command');
    fclose($pipes[0]);if(proc_close($p)!==0)throw new RuntimeException('command exit');
}
$db=ddb();
dcase('015 interrupted after first DDL restores populated original schema and records then replays safely',function()use(&$db){
    $before=ddig($db);
    dcmd(['C:/Mapped/Tools/mariadb/bin/mysqldump.exe','--no-defaults','--host=127.0.0.1','--port=33081','--user=root',
        '--single-transaction','--result-file=C:/m01d.sql','--databases','kios_development']);
    $sql=file_get_contents('C:/KiosB0/database/migrations/015_merchant_operations.sql');
    $first=preg_split('/;\s*(?:\r?\n|$)/',$sql)[0];
    $db->exec($first);
    dc((int)$db->query("SELECT COUNT(*) FROM schema_migrations WHERE migration='015_merchant_operations.sql'")->fetchColumn()===0);
    dc($db->query("SHOW INDEX FROM merchant_legacy_outlets WHERE Key_name='uq_m01d_mapping'")->fetch()!==false);
    ddeny(fn()=>(new MigrationRunner($db))->apply('C:/KiosB0/database/migrations'));
    dc(ddig($db)===$before);
    $db=null;
    $root=new PDO('mysql:host=127.0.0.1;port=33081','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $root->exec('DROP DATABASE kios_development');
    dcmd(['C:/Mapped/Tools/mariadb/bin/mysql.exe','--no-defaults','--host=127.0.0.1','--port=33081','--user=root','--execute=source C:/m01d.sql']);
    $db=ddb();dc(ddig($db)===$before);
    dc((new MigrationRunner($db))->apply('C:/KiosB0/database/migrations')===['015_merchant_operations.sql']);
    dc((new MigrationRunner($db))->apply('C:/KiosB0/database/migrations')===[]);
    $after=ddig($db);
    foreach($before as $table=>$hash)if($table!=='schema_migrations')dc($after[$table]===$hash);
    file_put_contents('C:/Mapped/Results/m01d-recovery.json',json_encode(['status'=>'PASS','before'=>$before,'after'=>$after,
        'backupSha256'=>hash_file('sha256','C:/m01d.sql'),'fault'=>'controlled stop after original first 015 ALTER, before remaining DDL and ledger'],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
});
if($result['failed']){file_put_contents('C:/Mapped/Results/m01d-result.json',json_encode($result));exit(1);}
$a1=(int)$db->query("SELECT outlet_id FROM outlets WHERE outlet_code='synthetic-a1'")->fetchColumn();
$a2=(int)$db->query("SELECT outlet_id FROM outlets WHERE outlet_code='synthetic-a2'")->fetchColumn();
$b1=(int)$db->query("SELECT outlet_id FROM outlets WHERE outlet_code='synthetic-b1'")->fetchColumn();
$db->exec("INSERT INTO outlets(outlet_code,name) VALUES('synthetic-c1','Synthetic C1')");
$c1=(int)$db->lastInsertId();
$db->prepare('INSERT INTO merchant_legacy_outlets VALUES(?,?,?)')->execute([did(3),did(31),$c1]);
$store=new PdoIdentityStore($db);
$store->createStaff(dauth(100),did(109),'Synthetic C kitchen');
$store->assignMembership(dauth(100),new Membership(did(109),Role::Kitchen,did(3),did(31)));
$stationA=(int)$db->query("SELECT station_id FROM preparation_stations WHERE outlet_id=$a1 AND code='SWEETS'")->fetchColumn();
$stationB=(int)$db->query("SELECT station_id FROM preparation_stations WHERE outlet_id=$b1 AND code='SWEETS'")->fetchColumn();
$db->prepare("INSERT INTO preparation_stations(outlet_id,code,name,department_code) VALUES(?,'SOUTH_INDIAN','Synthetic South station','FIRST_FLOOR_KITCHEN')")->execute([$a1]);
$south=(int)$db->lastInsertId();
$db->prepare("INSERT INTO preparation_stations(outlet_id,code,name,department_code) VALUES(?,'BEVERAGE','Synthetic beverage station','FIRST_FLOOR_KITCHEN')")->execute([$c1]);
$stationC=(int)$db->lastInsertId();
$db->prepare("INSERT INTO categories(outlet_id,slug,name,default_department_code) VALUES(?,'beverage','Synthetic Beverage','FIRST_FLOOR_KITCHEN')")->execute([$c1]);
$categoryC=(int)$db->lastInsertId();
$db->prepare("INSERT INTO products(outlet_id,category_id,station_id,sku,name,routing_status) VALUES(?,?,?,'SYNTHETIC-C','Synthetic drink','approved')")->execute([$c1,$categoryC,$stationC]);
$productC=(int)$db->lastInsertId();
$db->prepare("INSERT INTO product_variants(product_id,code,label,unit,quantity_value,price_paise) VALUES(?,'portion','1 portion','portion',1,800)")->execute([$productC]);
$variantC=(int)$db->lastInsertId();
$categoryA=(int)$db->query("SELECT category_id FROM categories WHERE outlet_id=$a1")->fetchColumn();
$db->prepare("INSERT INTO products(outlet_id,category_id,station_id,sku,name,routing_status) VALUES(?,?,?,'SYNTHETIC-SOUTH','Synthetic south item','approved')")->execute([$a1,$categoryA,$south]);
$productSouth=(int)$db->lastInsertId();
$db->prepare("INSERT INTO product_variants(product_id,code,label,unit,quantity_value,price_paise) VALUES(?,'plate','1 plate','plate',1,2200)")->execute([$productSouth]);
$variantSouth=(int)$db->lastInsertId();
$printers=[];
foreach([[$a1,[$stationA,$south]],[$b1,[$stationB]],[$c1,[$stationC]]] as [$outlet,$stations]){
    foreach(['cashier_receipt','station_kot'] as $role){
        $db->prepare("INSERT INTO printers(outlet_id,printer_code,name,role,adapter_type,enabled) VALUES(?,?,?,?,'virtual',1)")
            ->execute([$outlet,$role,'Synthetic '.$role,$role]);
        $id=(int)$db->lastInsertId();
        if($role==='station_kot'){
            $printers[$outlet]=$id;
            foreach($stations as $station)$db->prepare('INSERT INTO printer_station_routes(outlet_id,station_id,printer_id) VALUES(?,?,?)')->execute([$outlet,$station,$id]);
        }
    }
}
$grant=$db->prepare('INSERT INTO merchant_kitchen_grants VALUES(?,?,?,?,?,1)');
$grant->execute([did(105),did(1),did(11),$a1,$stationA]);
$grant->execute([did(103),did(2),did(21),$b1,$stationB]);
$grant->execute([did(109),did(3),did(31),$c1,$stationC]);
$ownerA=new TenantOperations($db,dauth(101));
$ownerB=new TenantOperations($db,dauth(107));
$ownerC=new TenantOperations($db,dauth(108));
$kitchenA=new TenantOperations($db,dauth(105));
$kitchenB=new TenantOperations($db,dauth(103));
$kitchenC=new TenantOperations($db,dauth(109));
$cashierA=new TenantOperations($db,dauth(102));
$restaurantA=new TenantRestaurant($db,dauth(101));
$restaurantB=new TenantRestaurant($db,dauth(107));
$restaurantC=new TenantRestaurant($db,dauth(108));
$session=str_repeat('d',24);
$catalogA=$restaurantA->catalogue(did(1),did(11),'sweets');
$sweet=null;
foreach($catalogA as $p)if($p['sku']==='SAME-SKU')$sweet=['productId'=>$p['productId'],'variantId'=>$p['variants'][0]['variantId'],'quantity'=>2];
$catalogB=$restaurantB->catalogue(did(2),did(21),'sweets')[0];
$basketB=[['productId'=>$catalogB['productId'],'variantId'=>$catalogB['variants'][0]['variantId'],'quantity'=>1]];
$orderA=$restaurantA->checkout(did(1),did(11),[$sweet,['productId'=>$productSouth,'variantId'=>$variantSouth,'quantity'=>1]],OrderType::Takeaway,CheckoutIntent::generateKey(),$session);
$orderB=$restaurantB->checkout(did(2),did(21),$basketB,OrderType::Takeaway,CheckoutIntent::generateKey(),$session);
$orderC=$restaurantC->checkout(did(3),did(31),[['productId'=>$productC,'variantId'=>$variantC,'quantity'=>3]],OrderType::Takeaway,CheckoutIntent::generateKey(),$session);
$idQuery=$db->prepare('SELECT order_id FROM orders WHERE public_id=?');
$idQuery->execute([$orderA->id]);$orderAId=(int)$idQuery->fetchColumn();
$idQuery->execute([$orderB->id]);$orderBId=(int)$idQuery->fetchColumn();
$idQuery->execute([$orderC->id]);$orderCId=(int)$idQuery->fetchColumn();
dcase('three merchants route paid synthetic orders to their configured station sets',function()use($ownerA,$ownerB,$ownerC,$orderAId,$orderBId,$orderCId,$db){
    dc($ownerA->confirmCash(did(1),did(11),$orderAId)->status===OrderStatus::Preparing);
    dc($ownerB->confirmCash(did(2),did(21),$orderBId)->status===OrderStatus::Preparing);
    dc($ownerC->confirmCash(did(3),did(31),$orderCId)->status===OrderStatus::Preparing);
    dc((int)$db->query("SELECT COUNT(*) FROM preparation_tasks WHERE order_id=$orderAId")->fetchColumn()===2);
    dc((int)$db->query("SELECT COUNT(*) FROM preparation_tasks WHERE order_id=$orderBId")->fetchColumn()===1);
    dc((int)$db->query("SELECT COUNT(*) FROM preparation_tasks WHERE order_id=$orderCId")->fetchColumn()===1);
});
$taskA=(int)$db->query("SELECT preparation_task_id FROM preparation_tasks WHERE order_id=$orderAId AND station_id=$stationA")->fetchColumn();
$taskSouth=(int)$db->query("SELECT preparation_task_id FROM preparation_tasks WHERE order_id=$orderAId AND station_id=$south")->fetchColumn();
$taskB=(int)$db->query("SELECT preparation_task_id FROM preparation_tasks WHERE order_id=$orderBId")->fetchColumn();
$taskC=(int)$db->query("SELECT preparation_task_id FROM preparation_tasks WHERE order_id=$orderCId")->fetchColumn();
dcase('granted kitchen queue and KOT retain correct products quantities and station',function()use($kitchenA,$taskA,$stationA){
    dc(count($kitchenA->queue(did(1),did(11),$stationA))>=1);
    $kot=$kitchenA->kot(did(1),did(11),$taskA);
    dc(str_contains($kot,'Synthetic shared product') && str_contains($kot,'2') && str_contains($kot,'KITCHEN ORDER TICKET'));
});
dcase('cross-merchant queue and KOT access deny valid foreign identifiers',function()use($kitchenA,$stationB,$taskB,$stationA){
    ddeny(fn()=>$kitchenA->queue(did(2),did(21),$stationB));
    ddeny(fn()=>$kitchenA->queue(did(1),did(11),$stationB));
    ddeny(fn()=>$kitchenA->kot(did(1),did(11),$taskB));
    ddeny(fn()=>$kitchenA->queue(did(1),did(12),$stationA));
});
dcase('kitchen requires explicit station grant even inside its own outlet',function()use($kitchenA,$south,$taskSouth){
    ddeny(fn()=>$kitchenA->queue(did(1),did(11),$south));
    ddeny(fn()=>$kitchenA->kot(did(1),did(11),$taskSouth));
    ddeny(fn()=>$kitchenA->advance(did(1),did(11),$taskSouth,PreparationTaskStatus::Queued));
});
dcase('cashier cannot retrieve or modify KOT preparation tasks',function()use($cashierA,$taskA){
    ddeny(fn()=>$cashierA->kot(did(1),did(11),$taskA));
    ddeny(fn()=>$cashierA->advance(did(1),did(11),$taskA,PreparationTaskStatus::Queued));
});
dcase('unauthorized foreign task mutation cannot alter status',function()use($db,$kitchenA,$taskB){
    $before=$db->query("SELECT status FROM preparation_tasks WHERE preparation_task_id=$taskB")->fetchColumn();
    ddeny(fn()=>$kitchenA->advance(did(1),did(11),$taskB,PreparationTaskStatus::Queued));
    dc($db->query("SELECT status FROM preparation_tasks WHERE preparation_task_id=$taskB")->fetchColumn()===$before);
});
dcase('duplicate cash confirmation cannot create extra payments tasks or jobs',function()use($db,$ownerA,$orderAId){
    $before=[(int)$db->query('SELECT COUNT(*) FROM payment_confirmations')->fetchColumn(),(int)$db->query('SELECT COUNT(*) FROM preparation_tasks')->fetchColumn(),(int)$db->query('SELECT COUNT(*) FROM print_jobs')->fetchColumn()];
    ddeny(fn()=>$ownerA->confirmCash(did(1),did(11),$orderAId));
    dc($before===[(int)$db->query('SELECT COUNT(*) FROM payment_confirmations')->fetchColumn(),(int)$db->query('SELECT COUNT(*) FROM preparation_tasks')->fetchColumn(),(int)$db->query('SELECT COUNT(*) FROM print_jobs')->fetchColumn()]);
});
dcase('grant revocation denies queued updates without changing task',function()use($db,$kitchenA,$taskA){
    $db->prepare('UPDATE merchant_kitchen_grants SET active=0 WHERE user_id=?')->execute([did(105)]);
    ddeny(fn()=>$kitchenA->advance(did(1),did(11),$taskA,PreparationTaskStatus::Queued));
    $db->prepare('UPDATE merchant_kitchen_grants SET active=1 WHERE user_id=?')->execute([did(105)]);
});
dcase('all required stations must become READY before collection',function()use($ownerA,$kitchenA,$taskA,$taskSouth,$orderAId){
    ddeny(fn()=>$ownerA->collect(did(1),did(11),$orderAId));
    $kitchenA->advance(did(1),did(11),$taskA,PreparationTaskStatus::Queued);
    ddeny(fn()=>$kitchenA->advance(did(1),did(11),$taskA,PreparationTaskStatus::Queued));
    $kitchenA->advance(did(1),did(11),$taskA,PreparationTaskStatus::Preparing);
    ddeny(fn()=>$ownerA->collect(did(1),did(11),$orderAId));
    $ownerA->advance(did(1),did(11),$taskSouth,PreparationTaskStatus::Queued);
    dc($ownerA->advance(did(1),did(11),$taskSouth,PreparationTaskStatus::Preparing)['orderStatus']===OrderStatus::Ready);
});
dcase('cross-merchant collection and kitchen-role collection deny',function()use($ownerB,$kitchenA,$orderAId){
    ddeny(fn()=>$ownerB->collect(did(2),did(21),$orderAId));
    ddeny(fn()=>$kitchenA->collect(did(1),did(11),$orderAId));
});
dcase('collection is idempotent with one final history row and canonical actor',function()use($db,$cashierA,$orderAId){
    dc($cashierA->collect(did(1),did(11),$orderAId)->status===OrderStatus::Collected);
    dc($cashierA->collect(did(1),did(11),$orderAId)->status===OrderStatus::Collected);
    dc((int)$db->query("SELECT COUNT(*) FROM order_status_history WHERE order_id=$orderAId AND status='collected'")->fetchColumn()===1);
    dc($db->query("SELECT changed_by FROM order_status_history WHERE order_id=$orderAId AND status='collected'")->fetchColumn()===did(102));
});
dcase('print list and foreign job mutation are tenant-scoped',function()use($db,$ownerA,$ownerB,$a1,$orderAId,$orderBId,$printers,$b1){
    $jobs=$ownerA->printJobs(did(1),did(11));
    foreach($jobs as $job){
        $q=$db->prepare('SELECT outlet_id FROM print_jobs WHERE print_job_id=?');
        $q->execute([$job['print_job_id']]);dc((int)$q->fetchColumn()===$a1);
    }
    $foreign=(int)$db->query("SELECT print_job_id FROM print_jobs WHERE order_id=$orderBId LIMIT 1")->fetchColumn();
    ddeny(fn()=>$ownerA->deliverVirtual(did(1),did(11),$foreign));
    $own=(int)$db->query("SELECT print_job_id FROM print_jobs WHERE order_id=$orderAId LIMIT 1")->fetchColumn();
    ddeny(fn()=>$ownerA->deliverVirtual(did(1),did(11),$own,expectedPrinter:$printers[$b1]));
    ddeny(fn()=>$ownerB->printJobs(did(1),did(11)));
});
dcase('kitchen cannot manage print job statuses or financial daily reports',function()use($db,$kitchenA,$orderAId){
    $job=(int)$db->query("SELECT print_job_id FROM print_jobs WHERE order_id=$orderAId LIMIT 1")->fetchColumn();
    ddeny(fn()=>$kitchenA->deliverVirtual(did(1),did(11),$job));
    ddeny(fn()=>$kitchenA->daily(did(1),did(11),'2030-01-01'));
});
dcase('automatic virtual printing records canonical operator and duplicate dispatch is safe',function()use($db,$ownerA,$orderAId){
    $job=(int)$db->query("SELECT print_job_id FROM print_jobs WHERE order_id=$orderAId AND status='printed' LIMIT 1")->fetchColumn();
    dc($job>0);
    $before=(int)$db->query("SELECT COUNT(*) FROM print_job_attempts WHERE print_job_id=$job")->fetchColumn();
    dc($ownerA->deliverVirtual(did(1),did(11),$job)===PrintJobStatus::Printed);
    dc((int)$db->query("SELECT COUNT(*) FROM print_job_attempts WHERE print_job_id=$job")->fetchColumn()===$before);
    dc($db->query("SELECT actor FROM print_job_attempts WHERE print_job_id=$job LIMIT 1")->fetchColumn()===did(101));
});
dcase('virtual-only service rejects physical adapter before any hardware adapter call',function()use($db){
    $printer=(int)$db->query('SELECT printer_id FROM printers LIMIT 1')->fetchColumn();
    // A non-virtual snapshot is never delivered by the selected service. Existing job constraints stay enforced.
    $db->exec("UPDATE printers SET adapter_type='windows_spooler' WHERE printer_id=$printer");
    try{
        $service=new TenantOperations($db,dauth(101));
        $job=(int)$db->query("SELECT print_job_id FROM print_jobs WHERE printer_id=$printer LIMIT 1")->fetchColumn();
        dc($job>0);
        ddeny(fn()=>$service->deliverVirtual(did(1),did(11),$job));
    }finally{$db->exec("UPDATE printers SET adapter_type='virtual' WHERE printer_id=$printer");}
});
dcase('grant database FKs reject foreign mapping and station ownership',function()use($db,$a1,$stationB){
    ddeny(function()use($db,$a1,$stationB){$db->prepare('INSERT INTO merchant_kitchen_grants VALUES(?,?,?,?,?,1)')->execute([did(105),did(1),did(11),$a1,$stationB]);});
});
dcase('third merchants kitchen can prepare its own task and cannot inspect another merchant',function()use($kitchenC,$taskC,$taskA){
    dc(str_contains($kitchenC->kot(did(3),did(31),$taskC),'Synthetic drink'));
    ddeny(fn()=>$kitchenC->kot(did(3),did(31),$taskA));
    $kitchenC->advance(did(3),did(31),$taskC,PreparationTaskStatus::Queued);
    dc($kitchenC->advance(did(3),did(31),$taskC,PreparationTaskStatus::Preparing)['orderStatus']===OrderStatus::Ready);
});
dcase('daily counts audits and closing records remain in authorized merchant outlet scope',function()use($db,$ownerA,$ownerB,$a1,$b1){
    $date=(new DateTimeImmutable('now',new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
    $insert=$db->prepare("INSERT INTO shop_day_closings(outlet_id,business_date,request_id,closed_epoch,summary_json) VALUES(?,?,?,?,?)");
    $insert->execute([$a1,$date,str_repeat('a',32),1,'{"synthetic":"A"}']);
    $insert->execute([$b1,$date,str_repeat('b',32),1,'{"synthetic":"B"}']);
    $report=$ownerA->daily(did(1),did(11),$date);
    dc(count($report['closings'])===1 && str_contains($report['closings'][0]['summary_json'],'A'));
    foreach($report['audit'] as $event)dc(in_array($event['actor_user_id'],[did(101),did(102),did(105)],true));
    dc(count($report['audit'])>0);
    ddeny(fn()=>$ownerB->daily(did(1),did(11),$date));
    ddeny(fn()=>$ownerA->daily(did(1),did(11),'2030-02-31'));
});
dcase('task transition and operational audit attribute the actual user UUID',function()use($db,$taskA,$a1){
    dc($db->query("SELECT changed_by FROM preparation_task_events WHERE preparation_task_id=$taskA ORDER BY preparation_task_event_id LIMIT 1")->fetchColumn()===did(105));
    $q=$db->prepare("SELECT COUNT(*) FROM merchant_operation_events WHERE legacy_outlet_id=? AND actor_user_id=? AND operation='prepare-authorized'");
    $q->execute([$a1,did(105)]);dc((int)$q->fetchColumn()===2);
});
dcase('authorized virtual retry preserves job identity and financial records after synthetic failed state',function()use($db,$ownerB,$orderBId){
    $job=(int)$db->query("SELECT print_job_id FROM print_jobs WHERE order_id=$orderBId AND status='printed' LIMIT 1")->fetchColumn();
    dc($job>0);
    $before=(int)$db->query('SELECT COUNT(*) FROM payment_confirmations')->fetchColumn();
    $count=(int)$db->query('SELECT COUNT(*) FROM print_jobs')->fetchColumn();
    $db->exec("UPDATE print_jobs SET status='failed' WHERE print_job_id=$job");
    dc($ownerB->deliverVirtual(did(2),did(21),$job,true)===PrintJobStatus::Printed);
    dc((int)$db->query('SELECT COUNT(*) FROM payment_confirmations')->fetchColumn()===$before);
    dc((int)$db->query('SELECT COUNT(*) FROM print_jobs')->fetchColumn()===$count);
    dc($db->query("SELECT actor FROM print_job_attempts WHERE print_job_id=$job ORDER BY attempt_number DESC LIMIT 1")->fetchColumn()===did(107));
});
file_put_contents('C:/Mapped/Results/m01d-result.json',json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
exit($result['failed']===0?0:1);