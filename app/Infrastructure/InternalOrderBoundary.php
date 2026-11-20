<?php
declare(strict_types=1);
namespace Kios\Infrastructure;
use PDO;
/** Finance/customer consumers must exclude internal work even when its feature flag is off. */
final class InternalOrderBoundary
{
    public static function assertSeatingUnbound(PDO $db,string $merchant,string $outlet,string $table):void
    {
        $exists=(int)$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='merchant_service_sessions'")->fetchColumn();if(!$exists)return;
        $q=$db->prepare("SELECT service_id FROM merchant_service_sessions WHERE merchant_id=? AND outlet_id=? AND table_id=? AND status IN ('DRAFT','SUBMITTED') FOR UPDATE");$q->execute([$merchant,$outlet,$table]);if($q->fetchColumn()!==false)throw new \DomainException('Complete or cancel the active table service before changing seating.');
    }
    public static function customerFilter(PDO $db,string $alias='o'):string
    {
        if(!in_array($alias,['o','orders'],true))throw new \InvalidArgumentException('Invalid order alias.');
        if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
            $q=$db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='orders' AND column_name='order_mode'");$present=(int)$q->fetchColumn()===1;
        }else{$present=(bool)array_filter($db->query('PRAGMA table_info(orders)')->fetchAll(PDO::FETCH_ASSOC),fn($r)=>$r['name']==='order_mode');}
        return $present?" AND $alias.order_mode='CUSTOMER' ":'';
    }
}
