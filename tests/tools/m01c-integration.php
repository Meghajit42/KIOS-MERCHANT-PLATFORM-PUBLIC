<?php
declare(strict_types=1);
require 'C:/KiosB0/app/bootstrap.php';

use Kios\Domain\{Money, OrderType, OrderStatus, CheckoutIntent};
use Kios\Infrastructure\{MigrationRunner, OrderRepository};
use Kios\MerchantFoundation\{AuthenticatedUserProvider, Membership, Role, PdoIdentityStore};
use Kios\MerchantOperations\TenantRestaurant;

$result=['passed'=>0,'failed'=>0,'cases'=>[]];
function cid(int $n):string {return '00000000-0000-4000-8000-'.str_pad((string)$n,12,'0',STR_PAD_LEFT);}
function cauth(int $n):AuthenticatedUserProvider {
    return new class(cid($n)) implements AuthenticatedUserProvider {
        public function __construct(private string $id){}
        public function currentUserId():?string{return $this->id;}
    };
}
function cc(bool $value):void {if(!$value)throw new RuntimeException('assertion');}
function cdeny(callable $action):void {
    try{$action();}catch(DomainException|InvalidArgumentException|PDOException $expected){return;}
    throw new RuntimeException('expected denial');
}
function ccase(string $name,callable $action):void {
    global $result;
    try{$action();$result['passed']++;$result['cases'][$name]='PASS';}
    catch(Throwable $e){$result['failed']++;$result['cases'][$name]=get_class($e).':'.$e->getCode().':'.$e->getMessage();}
}
function cdb():PDO {return new PDO('mysql:host=127.0.0.1;port=33081;dbname=kios_development','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);}
function cdig(PDO $db):array {
    $out=[];
    foreach($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t){
        $rows=$db->query('SELECT * FROM `'.$t.'`')->fetchAll(PDO::FETCH_ASSOC);
        $rows=array_map(fn($r)=>json_encode($r,JSON_THROW_ON_ERROR),$rows);sort($rows);
        $out[$t]=['count'=>count($rows),'digest'=>hash('sha256',implode("\n",$rows))];
    }
    ksort($out);return $out;
}
function ccmd(array $args):void {
    $p=proc_open($args,[0=>['pipe','r'],1=>['file','C:/m01c-command.out','a'],2=>['file','C:/m01c-command.err','a']],$pipes);
    if(!is_resource($p))throw new RuntimeException('command');
    fclose($pipes[0]);if(proc_close($p)!==0)throw new RuntimeException('command exit');
}
$db=cdb();
$db->exec("INSERT INTO outlets(outlet_code,name) VALUES('synthetic-a1','Synthetic A1')");
$a1=(int)$db->lastInsertId();
$db->exec("INSERT INTO orders(outlet_id,public_id,outlet_code_snapshot,token,order_type,status,total_paise)
 VALUES($a1,'SYNTHETIC-C-GATE','synthetic-a1','SYNTHETIC-C-GATE','takeaway','paid',1234)");
$gateOrder=(int)$db->lastInsertId();
$db->exec("INSERT INTO payment_confirmations(order_id,payment_method,amount_paise,confirmed_by)
 VALUES($gateOrder,'cash',1234,'synthetic-gate')");
ccase('014 interruption at DDL-to-ledger boundary detects partial state and restores populated backup',function()use(&$db){
    $before=cdig($db);
    ccmd(['C:/Mapped/Tools/mariadb/bin/mysqldump.exe','--no-defaults','--host=127.0.0.1','--port=33081',
        '--user=root','--single-transaction','--result-file=C:/m01c-backup.sql','--databases','kios_development']);
    $db->exec(file_get_contents('C:/KiosB0/database/migrations/014_tenant_legacy_outlets.sql'));
    cc($db->query("SHOW TABLES LIKE 'merchant_legacy_outlets'")->fetchColumn()!==false);
    cc((int)$db->query("SELECT COUNT(*) FROM schema_migrations WHERE migration='014_tenant_legacy_outlets.sql'")->fetchColumn()===0);
    cdeny(fn()=>(new MigrationRunner($db))->apply('C:/KiosB0/database/migrations'));
    $partial=cdig($db);
    foreach($before as $table=>$digest)cc($partial[$table]===$digest);
    $db=null;
    $root=new PDO('mysql:host=127.0.0.1;port=33081','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $root->exec('DROP DATABASE kios_development');
    ccmd(['C:/Mapped/Tools/mariadb/bin/mysql.exe','--no-defaults','--host=127.0.0.1','--port=33081','--user=root','--execute=source C:/m01c-backup.sql']);
    $db=cdb();cc(cdig($db)===$before);
    cc((new MigrationRunner($db))->apply('C:/KiosB0/database/migrations')===['014_tenant_legacy_outlets.sql']);
    cc((new MigrationRunner($db))->apply('C:/KiosB0/database/migrations')===[]);
    $after=cdig($db);
    foreach($before as $table=>$digest)if($table!=='schema_migrations')cc($after[$table]===$digest);
    file_put_contents('C:/Mapped/Results/m01c-recovery.json',json_encode([
        'status'=>'PASS','backupSha256'=>hash_file('sha256','C:/m01c-backup.sql'),
        'before'=>$before,'after'=>$after,'method'=>'Controlled stop after original 014 DDL, before ledger insertion; native backup restoration',
    ],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
});
if($result['failed']){
    file_put_contents('C:/Mapped/Results/m01c-result.json',json_encode($result,JSON_PRETTY_PRINT));exit(1);
}
$store=new PdoIdentityStore($db);
$store->assignMembership(cauth(100),new Membership(cid(106),Role::Cashier,cid(2),cid(21)));
$store->assignMembership(cauth(100),new Membership(cid(107),Role::MerchantOwner,cid(2),null));
$db->exec("INSERT INTO outlets(outlet_code,name) VALUES('synthetic-a2','Synthetic A2')");
$a2=(int)$db->lastInsertId();
$db->exec("INSERT INTO outlets(outlet_code,name) VALUES('synthetic-b1','Synthetic B1')");
$b1=(int)$db->lastInsertId();
$map=$db->prepare('INSERT INTO merchant_legacy_outlets VALUES(?,?,?)');
$map->execute([cid(1),cid(11),$a1]);$map->execute([cid(1),cid(12),$a2]);$map->execute([cid(2),cid(21),$b1]);
$products=[];
foreach([[$a1,1000],[$a2,2000],[$b1,5000]] as [$legacy,$price]){
    $db->prepare("INSERT INTO categories(outlet_id,slug,name,default_department_code) VALUES(?,'sweets','Synthetic sweets','GROUND_FLOOR')")->execute([$legacy]);
    $category=(int)$db->lastInsertId();
    $db->prepare("INSERT INTO preparation_stations(outlet_id,code,name,department_code) VALUES(?,'SWEETS','Synthetic station','GROUND_FLOOR')")->execute([$legacy]);
    $station=(int)$db->lastInsertId();
    $db->prepare("INSERT INTO products(outlet_id,category_id,station_id,sku,name,routing_status) VALUES(?,?,?,'SAME-SKU','Synthetic shared product','approved')")->execute([$legacy,$category,$station]);
    $product=(int)$db->lastInsertId();
    $db->prepare("INSERT INTO product_variants(product_id,code,label,unit,quantity_value,price_paise) VALUES(?,'portion','1 piece','piece',1,?)")->execute([$product,$price]);
    $products[$legacy]=['productId'=>$product,'variantId'=>(int)$db->lastInsertId(),'quantity'=>2];
}
$a=new TenantRestaurant($db,cauth(101));
$cashierA=new TenantRestaurant($db,cauth(102));
$b=new TenantRestaurant($db,cauth(107));
$cashierB=new TenantRestaurant($db,cauth(106));
$orderA=null;$orderB=null;
$key=CheckoutIntent::generateKey();$session=str_repeat('s',24);
ccase('catalogue identical SKUs resolve with independent scoped prices',function()use($a,$b){
    $one=$a->catalogue(cid(1),cid(11),'sweets');$two=$b->catalogue(cid(2),cid(21),'sweets');
    cc(count($one)===1 && count($two)===1 && $one[0]['sku']===$two[0]['sku']);
    cc($one[0]['productId']!==$two[0]['productId']);
    cc($one[0]['variants'][0]['pricePaise']===1000 && $two[0]['variants'][0]['pricePaise']===5000);
});
ccase('authorized merchant owner sees independently priced second outlet',function()use($a){
    cc($a->catalogue(cid(1),cid(12),'sweets')[0]['variants'][0]['pricePaise']===2000);
});
ccase('foreign merchant and unauthorized outlet catalogue contexts deny access',function()use($a,$cashierA){
    cdeny(fn()=>$a->catalogue(cid(2),cid(21),'sweets'));
    cdeny(fn()=>$cashierA->catalogue(cid(1),cid(12),'sweets'));
    cdeny(fn()=>$a->catalogue(cid(1),cid(21),'sweets'));
});
ccase('unknown or absent identity has no default tenant',function()use($db){
    $unknown=new TenantRestaurant($db,cauth(999));
    cdeny(fn()=>$unknown->catalogue(cid(1),cid(11),'sweets'));
    cdeny(fn()=>$unknown->catalogue('bad-context',cid(11),'sweets'));
});
ccase('foreign numeric product and variant references cannot be purchased',function()use($cashierA,$products,$a1,$b1,$session){
    cdeny(fn()=>$cashierA->checkout(cid(1),cid(11),[$products[$b1]],OrderType::Takeaway,CheckoutIntent::generateKey(),$session));
    $mixed=$products[$a1];$mixed['variantId']=$products[$b1]['variantId'];
    cdeny(fn()=>$cashierA->checkout(cid(1),cid(11),[$mixed],OrderType::Takeaway,CheckoutIntent::generateKey(),$session));
});
ccase('forged client price and invalid quantity are rejected',function()use($cashierA,$products,$a1,$session){
    $line=$products[$a1];$line['pricePaise']=1;
    cdeny(fn()=>$cashierA->checkout(cid(1),cid(11),[$line],OrderType::Takeaway,CheckoutIntent::generateKey(),$session));
    $line=$products[$a1];$line['quantity']=0;
    cdeny(fn()=>$cashierA->checkout(cid(1),cid(11),[$line],OrderType::Takeaway,CheckoutIntent::generateKey(),$session));
});
ccase('selected checkout creates correctly owned awaiting-payment snapshots',function()use($cashierA,$products,$a1,$key,$session,&$orderA){
    $orderA=$cashierA->checkout(cid(1),cid(11),[$products[$a1]],OrderType::Takeaway,$key,$session);
    cc($orderA->status===OrderStatus::AwaitingPayment && $orderA->outletId==='synthetic-a1');
    cc($orderA->total->paise()===2000 && $orderA->items[0]->unitPrice->paise()===1000);
});
ccase('same intent retry returns same order and no duplicate history',function()use($db,$cashierA,$products,$a1,$key,$session,&$orderA){
    $before=$db->query('SELECT COUNT(*) FROM orders')->fetchColumn();
    $again=$cashierA->checkout(cid(1),cid(11),[$products[$a1]],OrderType::Takeaway,$key,$session);
    cc($again->id===$orderA->id && $db->query('SELECT COUNT(*) FROM orders')->fetchColumn()===$before);
});
ccase('independent merchant may reuse raw intent key without collision or data leakage',function()use($cashierB,$products,$b1,$key,$session,&$orderB,&$orderA){
    $orderB=$cashierB->checkout(cid(2),cid(21),[$products[$b1]],OrderType::Takeaway,$key,$session);
    cc($orderB->id!==$orderA->id && $orderB->total->paise()===10000 && $orderB->outletId==='synthetic-b1');
});
ccase('tenant order retrieval cannot return another merchants order',function()use($db,$a,$b,&$orderA,&$orderB){
    $idA=(int)$db->query("SELECT order_id FROM orders WHERE public_id=".$db->quote($orderA->id))->fetchColumn();
    $idB=(int)$db->query("SELECT order_id FROM orders WHERE public_id=".$db->quote($orderB->id))->fetchColumn();
    cc($a->order(cid(1),cid(11),$idA)?->id===$orderA->id);
    cc($a->order(cid(1),cid(11),$idB)===null);
    cc($b->order(cid(2),cid(21),$idA)===null);
    cdeny(fn()=>$a->order(cid(2),cid(21),$idB));
});
ccase('foreign price mutation and cashier pricing privilege escalation are denied',function()use($a,$cashierA,$products,$b1,$a1){
    cdeny(fn()=>$a->setPrice(cid(1),cid(11),$products[$b1]['productId'],$products[$b1]['variantId'],Money::fromPaise(1),1));
    cdeny(fn()=>$cashierA->setPrice(cid(1),cid(11),$products[$a1]['productId'],$products[$a1]['variantId'],Money::fromPaise(1),1));
});
ccase('authorized price edit is versioned audited and preserves prior order snapshot',function()use($db,$a,$products,$a1,&$orderA){
    $a->setPrice(cid(1),cid(11),$products[$a1]['productId'],$products[$a1]['variantId'],Money::fromPaise(1300),1);
    $id=(int)$db->query("SELECT order_id FROM orders WHERE public_id=".$db->quote($orderA->id))->fetchColumn();
    cc($a->order(cid(1),cid(11),$id)->total->paise()===2000);
    cc($a->catalogue(cid(1),cid(11),'sweets')[0]['variants'][0]['pricePaise']===1300);
    cc((int)$db->query("SELECT COUNT(*) FROM menu_admin_events WHERE action='tenant-price'")->fetchColumn()===1);
    cdeny(fn()=>$a->setPrice(cid(1),cid(11),$products[$a1]['productId'],$products[$a1]['variantId'],Money::fromPaise(1500),1));
});
ccase('new checkout uses updated authoritative price while retry retains original snapshot',function()use($cashierA,$products,$a1,$key,$session,&$orderA){
    $again=$cashierA->checkout(cid(1),cid(11),[$products[$a1]],OrderType::Takeaway,$key,$session);
    cc($again->id===$orderA->id && $again->total->paise()===2000);
    $new=$cashierA->checkout(cid(1),cid(11),[$products[$a1]],OrderType::Takeaway,CheckoutIntent::generateKey(),$session);
    cc($new->total->paise()===2600);
});
ccase('changed session or cart cannot reuse existing intent',function()use($cashierA,$products,$a1,$key,$session){
    cdeny(fn()=>$cashierA->checkout(cid(1),cid(11),[$products[$a1]],OrderType::Takeaway,$key,str_repeat('t',24)));
    $changed=$products[$a1];$changed['quantity']=3;
    cdeny(fn()=>$cashierA->checkout(cid(1),cid(11),[$changed],OrderType::Takeaway,$key,$session));
});
ccase('availability off blocks new checkout and hides catalogue',function()use($db,$cashierA,$a,$products,$a1,$session){
    $id=$products[$a1]['productId'];$db->exec("UPDATE products SET available=0 WHERE product_id=$id");
    cc($a->catalogue(cid(1),cid(11),'sweets')===[]);
    cdeny(fn()=>$cashierA->checkout(cid(1),cid(11),[$products[$a1]],OrderType::Takeaway,CheckoutIntent::generateKey(),$session));
    $db->exec("UPDATE products SET available=1 WHERE product_id=$id");
});
ccase('no payment confirmation print obligation or preparation task is created by checkout',function()use($db){
    cc((int)$db->query('SELECT COUNT(*) FROM payment_confirmations')->fetchColumn()===1);
    cc((int)$db->query('SELECT COUNT(*) FROM preparation_tasks')->fetchColumn()===0);
    cc((int)$db->query('SELECT COUNT(*) FROM print_jobs')->fetchColumn()===0);
});
ccase('mapping FK and unique constraints prevent inconsistent or duplicate ownership',function()use($db,$a1){
    cdeny(function()use($db,$a1){$db->prepare('INSERT INTO merchant_legacy_outlets VALUES(?,?,?)')->execute([cid(2),cid(11),$a1]);});
    cdeny(function()use($db,$a1){$db->prepare('INSERT INTO merchant_legacy_outlets VALUES(?,?,?)')->execute([cid(3),cid(31),$a1]);});
});
ccase('unmapped tenant outlet fails closed',function()use($db){
    $db->prepare('INSERT INTO merchant_memberships VALUES(?,?,?,1)')->execute([cid(108),cid(3),'merchant_owner']);
    $service=new TenantRestaurant($db,cauth(108));
    cdeny(fn()=>$service->catalogue(cid(3),cid(31),'sweets'));
});
ccase('revoked staff loses selected application access immediately',function()use($db,$cashierA){
    $db->prepare('UPDATE merchant_staff SET active=0 WHERE user_id=?')->execute([cid(102)]);
    cdeny(fn()=>$cashierA->catalogue(cid(1),cid(11),'sweets'));
    $db->prepare('UPDATE merchant_staff SET active=1 WHERE user_id=?')->execute([cid(102)]);
});
ccase('closed shop blocks synthetic selected checkout when lifecycle is enabled',function()use($cashierA,$products,$a1,$session){
    putenv('KIOS_SHOP_OPERATIONS_ENABLED=1');
    try{cdeny(fn()=>$cashierA->checkout(cid(1),cid(11),[$products[$a1]],OrderType::Takeaway,CheckoutIntent::generateKey(),$session));}
    finally{putenv('KIOS_SHOP_OPERATIONS_ENABLED=0');}
});
ccase('checkout authority is rechecked inside the write transaction',function()use($db,$products,$a1,$session){
    $auth=new class implements AuthenticatedUserProvider {
        private int $calls=0;
        public function currentUserId():?string {return ++$this->calls===1?cid(102):cid(106);}
    };
    $before=$db->query('SELECT COUNT(*) FROM orders')->fetchColumn();
    $service=new TenantRestaurant($db,$auth);
    cdeny(fn()=>$service->checkout(cid(1),cid(11),[$products[$a1]],OrderType::Takeaway,CheckoutIntent::generateKey(),$session));
    cc(!$db->inTransaction() && $db->query('SELECT COUNT(*) FROM orders')->fetchColumn()===$before);
});
ccase('cross-outlet checkout and combined quantity overflow deny before persistence',function()use($cashierA,$a,$products,$a1,$a2,$session){
    cdeny(fn()=>$cashierA->checkout(cid(1),cid(12),[$products[$a2]],OrderType::Takeaway,CheckoutIntent::generateKey(),$session));
    $line=$products[$a1];$line['quantity']=60;
    cdeny(fn()=>$a->checkout(cid(1),cid(11),[$line,$line],OrderType::Takeaway,CheckoutIntent::generateKey(),$session));
});
file_put_contents('C:/Mapped/Results/m01c-result.json',json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
exit($result['failed']===0?0:1);