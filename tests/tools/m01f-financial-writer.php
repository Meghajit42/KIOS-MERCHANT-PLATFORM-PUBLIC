<?php
declare(strict_types=1);
// Guest-only concurrency fixture, not a routable application worker.
require 'C:/KiosB0/app/bootstrap.php';
use Kios\Infrastructure\{Database,Environment,OrderRepository,ShopOperations};
use Kios\Domain\PaymentMethod;
if(PHP_SAPI!=='cli' || Environment::get('APP_ENV','')!=='local')exit(1);
$db=Database::connect();$id=filter_var($argv[1]??'',FILTER_VALIDATE_INT);
$q=$db->prepare("SELECT o.outlet_id,o.token FROM orders o JOIN outlets l ON l.outlet_id=o.outlet_id WHERE o.order_id=? AND l.outlet_code='synthetic-a2' AND o.status='awaiting_payment'");
$q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);if(!$row)exit(1);
try {
    $actor='00000000-0000-4000-8000-000000000101';
    $first=true;
    $barrier=static function()use($db,$row,&$first):void {
        if(!$first)return;$first=false;
        (new ShopOperations($db))->serializeStaffMutation((int)$row['outlet_id']);
        file_put_contents('C:/M01FWriter/ready','1');$deadline=microtime(true)+15;
        while(!is_file('C:/M01FWriter/continue')){if(microtime(true)>$deadline)throw new RuntimeException('Fixture barrier timeout');usleep(20000);}
    };
    // Trusted fixture actor; non-null guard also enforces the virtual-only adapter factory.
    (new OrderRepository($db,operationalGuard:$barrier,operatorIdentity:$actor))->confirmPaymentByInternalToken($row['token'],PaymentMethod::Cash,$actor,(int)$row['outlet_id']);
    file_put_contents('C:/M01FWriter/done','1');
}catch(Throwable $error){if($db->inTransaction())$db->rollBack();file_put_contents('C:/M01FWriter/failed',get_class($error));exit(1);}
