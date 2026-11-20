<?php
declare(strict_types=1);
namespace Kios\MerchantOperations;
use PDO;
use Kios\Infrastructure\Environment;

/** Administrative account restrictions supplement lifecycle and native RBAC. No identity deactivation. */
final class PlatformAccess
{
    public static function enabled():bool{return Environment::get('KIOS_PLATFORM_ADMIN_ENABLED','0')==='1' && Entitlements::lifecycleEnabled();}
    private static function rows(PDO $db,string $sql,array $args):array{$q=$db->prepare($sql);$q->execute($args);return $q->fetchAll(PDO::FETCH_ASSOC);}
    public static function status(PDO $db,string $merchant,?string $outlet=null):array
    {
        if(!self::enabled())return ['merchant'=>'ACTIVE_DEMO','outlet'=>'ACTIVE_DEMO'];
        $lock=$db->inTransaction()?' FOR UPDATE':'';
        if($lock)self::rows($db,'SELECT version FROM merchant_plan_heads WHERE merchant_id=? FOR UPDATE',[$merchant]);
        $m=self::rows($db,'SELECT status FROM merchant_platform_controls WHERE merchant_id=?'.$lock,[$merchant]);
        $o=$outlet?self::rows($db,'SELECT status FROM merchant_platform_outlets WHERE merchant_id=? AND outlet_id=?'.$lock,[$merchant,$outlet]):[];
        return ['merchant'=>$m[0]['status']??'ACTIVE_DEMO','outlet'=>$o[0]['status']??'ACTIVE_DEMO'];
    }
    public static function canStart(PDO $db,string $merchant,string $outlet):bool
    {
        $s=self::status($db,$merchant,$outlet);return $s['merchant']==='ACTIVE_DEMO'&&$s['outlet']==='ACTIVE_DEMO';
    }
    public static function hasWork(PDO $db,string $merchant,?string $outlet=null):bool
    {
        foreach([
            ['merchant_service_sessions',"status IN ('DRAFT','SUBMITTED')"],
            ['merchant_seating_tables',"operational_state IN ('OCCUPIED','RESERVED')"],
            ['merchant_seating_reservations',"status IN ('REQUESTED','CONFIRMED','SEATED')"],
        ]as[$table,$where]){
            $args=[$merchant];$sql='SELECT COUNT(*) n FROM '.$table.' WHERE merchant_id=? AND '.$where;
            if($outlet!==null){$sql.=' AND outlet_id=?';$args[]=$outlet;}
            if((int)self::rows($db,$sql,$args)[0]['n'])return true;
        }
        $args=[$merchant];$sql="SELECT COUNT(*) n FROM preparation_tasks t JOIN merchant_legacy_outlets l ON l.legacy_outlet_id=t.outlet_id WHERE l.merchant_id=? AND t.status IN ('queued','preparing')";
        if($outlet!==null){$sql.=' AND l.merchant_outlet_id=?';$args[]=$outlet;}
        return (int)self::rows($db,$sql,$args)[0]['n']>0;
    }
}
