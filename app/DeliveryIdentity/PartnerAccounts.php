<?php
declare(strict_types=1);
namespace Kios\DeliveryIdentity;

use PDO;
use Kios\Infrastructure\Environment;
use Kios\MerchantFoundation\{AuthenticatedUserProvider,Identity};
use Kios\MerchantOperations\MerchantProvisioner;

/** Internal synthetic partner domain. No credential storage, HTTP identity, GPS, assignment or dispatch. */
final class PartnerAccounts
{
    public function __construct(private PDO $db) {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql') throw new \LogicException('MariaDB required.');
        $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);
    }
    private function guard(): void {
        if (Environment::get('APP_ENV')!=='demo' || Environment::get('KIOS_DELIVERY_IDENTITY_ENABLED','0')!=='1') throw new \DomainException('Partner foundation disabled.');
        foreach (['KIOS_PUBLIC_ORDERING_ENABLED','KIOS_PUBLIC_REGISTRATION_ENABLED','KIOS_PAYMENTS_ENABLED','KIOS_PHYSICAL_PRINTING_ENABLED','KIOS_DELIVERY_DISPATCH_ENABLED','KIOS_DELIVERY_REGISTRATION_ENABLED','KIOS_DELIVERY_GPS_ENABLED'] as $flag) {
            if (Environment::get($flag,'0')!=='0') throw new \DomainException('Synthetic isolated partner boundary required.');
        }
        if ($this->db->inTransaction()) throw new \LogicException('Partner service owns transaction.');
    }
    private function rows(string $sql,array $args=[]): array {
        $q=$this->db->prepare($sql);$q->execute($args);return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    private function lock(string $id): array {
        Identity::validate($id);$rows=$this->rows('SELECT * FROM delivery_partner_accounts WHERE partner_id=? FOR UPDATE',[$id]);
        if (count($rows)!==1) throw new \DomainException('Partner unavailable.');return $rows[0];
    }
    private function label(string $name): string {
        if (!preg_match('/^Synthetic [A-Za-z0-9 ._-]{1,80}$/D',$name)) throw new \InvalidArgumentException('Synthetic label required.');return $name;
    }
    private function revision(array $row,int $expected): void {
        if ($expected<1 || (int)$row['version']!==$expected) throw new \DomainException('Partner revision conflict.');
    }
    private function event(string $id,string $domain,string $actor,string $action,int $version): void {
        $this->rows('INSERT INTO delivery_partner_events(event_id,partner_id,actor_domain,actor_partner_id,actor_staff_id,action_code,partner_version,occurred_epoch) VALUES(?,?,?,?,?,?,?,?)',[
            bin2hex(random_bytes(16)),$id,$domain,$domain==='PARTNER'?$actor:null,$domain==='PLATFORM'?$actor:null,$action,$version,time()
        ]);
    }
    private function model(array $row): array {
        $areas=json_decode($row['service_areas_json'],true,16,JSON_THROW_ON_ERROR);
        $capabilities=json_decode($row['capabilities_json'],true,16,JSON_THROW_ON_ERROR);
        return ['partnerId'=>$row['partner_id'],'handle'=>$row['partner_handle'],'displayName'=>$row['display_name'],'status'=>$row['status'],
            'eligibility'=>$row['eligibility'],'availability'=>$row['availability'],'serviceAreas'=>$areas,'capabilities'=>$capabilities,
            'version'=>(int)$row['version'],'createdEpoch'=>(int)$row['created_epoch'],'updatedEpoch'=>(int)$row['updated_epoch'],
            'operationallyEligible'=>$row['status']==='ACTIVE' && $row['eligibility']==='ELIGIBLE' && $row['availability']==='AVAILABLE' && $areas!==[] && $capabilities!==[],
            'simulationOnly'=>true,'dispatchAuthorized'=>false];
    }
    private function own(PartnerIdentityProvider $auth,string $id,callable $work): array {
        $this->guard();Identity::validate($id);
        if ($auth->currentPartnerId()!==$id) throw new \DomainException('Partner ownership required.');
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$this->db->beginTransaction();
        try {
            $row=$this->lock($id);
            if ($auth->currentPartnerId()!==$id || $row['status']==='DEACTIVATED') throw new \DomainException('Partner unavailable.');
            $result=$work($row);
            if ($auth->currentPartnerId()!==$id) throw new \DomainException('Partner identity changed.');
            $this->db->commit();return $result;
        } catch (\Throwable $error) { if ($this->db->inTransaction()) $this->db->rollBack();throw $error; }
    }
    public function create(AuthenticatedUserProvider $platform,string $handle,string $name): array {
        $this->guard();$handle=strtolower(trim($handle));$name=$this->label($name);
        if (!preg_match('/^synthetic-partner-[a-z0-9][a-z0-9_-]{2,44}$/D',$handle)) throw new \InvalidArgumentException('Synthetic partner handle required.');
        return (new MerchantProvisioner($this->db,$platform))->administer(function(string $actor) use ($handle,$name): array {
            $id=Identity::generate();$now=time();
            $this->rows("INSERT INTO delivery_partner_accounts VALUES(?,?,?,'PENDING','INELIGIBLE','OFFLINE','[]','[]',1,?,?)",[$id,$handle,$name,$now,$now]);
            $this->event($id,'PLATFORM',$actor,'CREATE',1);return $this->model($this->lock($id));
        });
    }
    public function transition(AuthenticatedUserProvider $platform,string $id,int $expected,string $next): array {
        $this->guard();return (new MerchantProvisioner($this->db,$platform))->administer(function(string $actor) use ($id,$expected,$next): array {
            $row=$this->lock($id);$this->revision($row,$expected);
            $allowed=['PENDING'=>['ACTIVE','DEACTIVATED'],'ACTIVE'=>['SUSPENDED','DEACTIVATED'],'SUSPENDED'=>['ACTIVE','DEACTIVATED'],'DEACTIVATED'=>[]];
            if (!in_array($next,$allowed[$row['status']],true)) throw new \DomainException('Invalid partner transition.');
            $this->rows("UPDATE delivery_partner_accounts SET status=?,eligibility='INELIGIBLE',availability='OFFLINE',version=version+1,updated_epoch=? WHERE partner_id=?",[$next,time(),$id]);
            $this->event($id,'PLATFORM',$actor,'STATE_'.$next,$expected+1);return $this->model($this->lock($id));
        });
    }
    public function eligibility(AuthenticatedUserProvider $platform,string $id,int $expected,string $next): array {
        $this->guard();if (!in_array($next,['ELIGIBLE','INELIGIBLE'],true)) throw new \InvalidArgumentException('Explicit eligibility required.');
        return (new MerchantProvisioner($this->db,$platform))->administer(function(string $actor) use ($id,$expected,$next): array {
            $row=$this->lock($id);$this->revision($row,$expected);
            if ($row['status']!=='ACTIVE' || $row['eligibility']===$next) throw new \DomainException('Current active partner and changed eligibility required.');
            $this->rows("UPDATE delivery_partner_accounts SET eligibility=?,availability='OFFLINE',version=version+1,updated_epoch=? WHERE partner_id=?",[$next,time(),$id]);
            $this->event($id,'PLATFORM',$actor,'ELIGIBILITY_'.$next,$expected+1);return $this->model($this->lock($id));
        });
    }
    public function profile(PartnerIdentityProvider $auth,string $id): array { return $this->own($auth,$id,fn(array $r): array=>$this->model($r)); }
    public function updateProfile(PartnerIdentityProvider $auth,string $id,int $expected,string $name): array {
        $name=$this->label($name);return $this->own($auth,$id,function(array $row) use ($id,$expected,$name): array {
            $this->revision($row,$expected);if ($row['status']!=='ACTIVE') throw new \DomainException('Active partner required.');
            $this->rows('UPDATE delivery_partner_accounts SET display_name=?,version=version+1,updated_epoch=? WHERE partner_id=?',[$name,time(),$id]);
            $this->event($id,'PARTNER',$id,'PROFILE',$expected+1);return $this->model($this->lock($id));
        });
    }
    public function configure(PartnerIdentityProvider $auth,string $id,int $expected,array $areas,array $capabilities): array {
        if (!array_is_list($areas)||count($areas)>8||count(array_unique($areas,SORT_REGULAR))!==count($areas)) throw new \InvalidArgumentException('Bounded unique area list required.');
        foreach ($areas as $area) if (!is_string($area)||!preg_match('/^synthetic-area-[a-z0-9][a-z0-9-]{0,23}$/D',$area)) throw new \InvalidArgumentException('Synthetic area classification required.');
        if (!array_is_list($capabilities)||count($capabilities)>2||count(array_unique($capabilities,SORT_REGULAR))!==count($capabilities)) throw new \InvalidArgumentException('Bounded capability list required.');
        foreach ($capabilities as $v) if (!is_string($v)||!in_array($v,['synthetic-foot','synthetic-cycle'],true)) throw new \InvalidArgumentException('Approved synthetic capability required.');
        sort($areas,SORT_STRING);sort($capabilities,SORT_STRING);
        return $this->own($auth,$id,function(array $row) use ($id,$expected,$areas,$capabilities): array {
            $this->revision($row,$expected);if ($row['status']!=='ACTIVE') throw new \DomainException('Active partner required.');
            $this->rows("UPDATE delivery_partner_accounts SET service_areas_json=?,capabilities_json=?,availability='OFFLINE',version=version+1,updated_epoch=? WHERE partner_id=?",[json_encode($areas,JSON_THROW_ON_ERROR),json_encode($capabilities,JSON_THROW_ON_ERROR),time(),$id]);
            $this->event($id,'PARTNER',$id,'CONFIGURE',$expected+1);return $this->model($this->lock($id));
        });
    }
    public function availability(PartnerIdentityProvider $auth,string $id,int $expected,string $next): array {
        if (!in_array($next,['OFFLINE','AVAILABLE','TEMPORARILY_UNAVAILABLE'],true)) throw new \InvalidArgumentException('Availability state required.');
        return $this->own($auth,$id,function(array $row) use ($id,$expected,$next): array {
            $this->revision($row,$expected);
            if ($row['status']!=='ACTIVE'||$row['availability']===$next) throw new \DomainException('Active partner and changed availability required.');
            if ($next==='AVAILABLE' && ($row['eligibility']!=='ELIGIBLE'||json_decode($row['service_areas_json'],true)===[]||json_decode($row['capabilities_json'],true)===[])) throw new \DomainException('Eligible configured partner required.');
            $this->rows('UPDATE delivery_partner_accounts SET availability=?,version=version+1,updated_epoch=? WHERE partner_id=?',[$next,time(),$id]);
            $this->event($id,'PARTNER',$id,'AVAILABILITY_'.$next,$expected+1);return $this->model($this->lock($id));
        });
    }
}
