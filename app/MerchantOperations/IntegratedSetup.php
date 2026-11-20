<?php
declare(strict_types=1);
namespace Kios\MerchantOperations;

use PDO;
use DomainException;
use InvalidArgumentException;
use Kios\Infrastructure\Environment;
use Kios\MerchantFoundation\{AuthenticatedUserProvider,AuthorizationService,Identity,PdoIdentityStore,TenantContext,Permission};

/** A guided orchestration/read model, not a replacement provisioning, RBAC or restaurant engine. */
final class IntegratedSetup
{
    public function __construct(private PDO $db,private AuthenticatedUserProvider $auth){}
    public static function enabled():bool{return Environment::get('APP_ENV')==='demo'&&Environment::get('KIOS_INTEGRATED_SETUP_ENABLED','0')==='1'&&PlatformAccess::enabled();}
    private function rows(string $sql,array $args=[]):array{$q=$this->db->prepare($sql);$q->execute($args);return$q->fetchAll(PDO::FETCH_ASSOC);}
    private function gate():void{if(!self::enabled())throw new DomainException('Integrated private setup disabled.');}
    /** Called only from the native M02/M06C creation transaction. No new creation mechanism. */
    public static function initialize(PDO $db,string $m,string $o,string $actor):void
    {
        if(!self::enabled()||!$db->inTransaction())throw new \LogicException('Native creation transaction required.');
        foreach([$m,$o,$actor]as$v)Identity::validate($v);
        $q=$db->prepare('INSERT INTO merchant_outlet_configuration VALUES(?,?,0,?,?,0,?,?)');$q->execute([$m,$o,'{}','[]',$actor,time()]);
    }
    public static function configuration(PDO $db,string $m,string $o):?array
    {
        if(!self::enabled())return null;$q=$db->prepare('SELECT * FROM merchant_outlet_configuration WHERE merchant_id=? AND outlet_id=?'.($db->inTransaction()?' FOR UPDATE':''));$q->execute([$m,$o]);$r=$q->fetch(PDO::FETCH_ASSOC);return$r?:null;
    }
    public static function permits(PDO $db,string $m,string $o,string $feature,string $operation):bool
    {
        $c=self::configuration($db,$m,$o);if(!$c)return true;
        if(!in_array($feature,json_decode($c['modules_json'],true),true))return false;
        return$feature!=='pos'||in_array($operation,['drain','drain-read'],true)||(bool)$c['reviewed'];
    }
    public static function reviewed(PDO $db,string $m,string $o):bool{$c=self::configuration($db,$m,$o);return$c===null||(bool)$c['reviewed'];}
    private function authority(string $m,string $o,bool $platform,bool $write):array
    {
        $this->gate();Identity::validate($m);Identity::validate($o);
        if($platform){(new AuthorizationService($this->auth,new PdoIdentityStore($this->db)))->requirePlatformAdministration();return['actor'=>$this->auth->currentUserId(),'owner'=>false,'manager'=>true];}
        $c=TenantContext::resolve($this->auth,new PdoIdentityStore($this->db),$m,$o);$owner=$c->allows(Permission::ManageMerchant);
        if(!$owner&&($write||!$c->allows(Permission::ManageOutlet)))throw new DomainException('Original merchant owner manages setup; assigned outlet managers may inspect their scoped dashboard.');
        return['actor'=>$c->userId,'owner'=>$owner,'manager'=>$c->allows(Permission::ManageOutlet)];
    }
    private static function text(mixed $v,int $max):string{if(!is_string($v)||!mb_check_encoding($v,'UTF-8')||trim($v)===''||mb_strlen($v)>$max||preg_match('/[<>\x00-\x1f\x7f]/u',$v))throw new InvalidArgumentException('Complete the plain-text fields within their limits.');return trim($v);}
    private static function profile(array $d):array
    {
        if(array_diff(array_keys($d),['address','contact'])||count($d)!==2)throw new InvalidArgumentException('Use only outlet address and contact.');$address=self::text($d['address'],500);$contact=self::text($d['contact'],120);
        if(!filter_var($contact,FILTER_VALIDATE_EMAIL)&&!preg_match('/^[+0-9 ()-]{6,30}$/D',$contact))throw new InvalidArgumentException('Enter a valid outlet email or phone number.');return['address'=>$address,'contact'=>$contact];
    }
    private static function modules(array $modules):array
    {
        if(!array_is_list($modules)||count($modules)>count(Entitlements::FEATURES))throw new InvalidArgumentException('Choose supported modules.');foreach($modules as$f)if(!is_string($f)||!in_array($f,Entitlements::FEATURES,true))throw new InvalidArgumentException('Unsupported module.');
        if(count(array_unique($modules))!==count($modules))throw new InvalidArgumentException('Choose each module once.');
        foreach(Entitlements::FEATURE_DEPENDENCIES as$f=>$deps)if(in_array($f,$modules,true)&&array_diff($deps,$modules))throw new InvalidArgumentException('Include prerequisites for '.$f.': '.implode(', ',$deps));sort($modules);return$modules;
    }
    public function act(string $m,string $o,string $expected,string $request,string $operation,array $data,string $reason,string $confirm,bool $platform):array
    {
        $a=$this->authority($m,$o,$platform,true);if($this->db->inTransaction())throw new \LogicException('Guided setup owns transaction.');
        if(!preg_match('/^[a-f0-9]{32}$/D',$request)||!preg_match('/^(0|[1-9][0-9]{0,7})$/D',$expected)||$confirm!=='yes')throw new InvalidArgumentException('Confirm the current synthetic configuration.');$reason=self::text($reason,240);
        if($operation==='save'){
            if(array_diff(array_keys($data),['profile','modules'])||count($data)!==2||!is_array($data['profile'])||!is_array($data['modules']))throw new InvalidArgumentException('Use the displayed profile and modules only.');$data=['profile'=>self::profile($data['profile']),'modules'=>self::modules($data['modules'])];
        }elseif($operation!=='review'||$data!==[])throw new InvalidArgumentException('Invalid setup action.');
        $hash=hash('sha256',json_encode([$m,$o,$expected,$operation,$data,$reason],JSON_THROW_ON_ERROR));$actor=$a['actor'];
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$this->db->beginTransaction();try{
            if($this->auth->currentUserId()!==$actor)throw new DomainException('Current native identity required.');
            if($platform){if(!$this->rows("SELECT p.user_id FROM merchant_platform_memberships p JOIN merchant_staff s ON s.user_id=p.user_id AND s.active=1 JOIN merchant_role_permissions r ON r.role_code=p.role_code AND r.permission_code='manage_platform' WHERE p.user_id=? AND p.active=1 FOR UPDATE",[$actor]))throw new DomainException('Current platform authority required.');}
            else OwnerOutletScope::locked($this->db,$this->auth,$m,$o,$actor);
            if(!$this->rows('SELECT o.outlet_id FROM merchant_outlets o JOIN merchant_organizations m ON m.merchant_id=o.merchant_id JOIN merchant_legacy_outlets l ON l.merchant_id=o.merchant_id AND l.merchant_outlet_id=o.outlet_id WHERE o.merchant_id=? AND o.outlet_id=? FOR UPDATE',[$m,$o]))throw new DomainException('Native mapped outlet required.');
            $this->rows('SELECT version FROM merchant_plan_heads WHERE merchant_id=? FOR UPDATE',[$m]);
            $c=self::configuration($this->db,$m,$o);if(!$c){self::initialize($this->db,$m,$o,$actor);$c=self::configuration($this->db,$m,$o);}
            $prior=$this->rows('SELECT * FROM merchant_integration_events WHERE request_id=? FOR UPDATE',[$request]);
            if($prior){$p=$prior[0];if($p['merchant_id']!==$m||$p['outlet_id']!==$o||$p['actor_id']!==$actor||!hash_equals($p['payload_hash'],$hash))throw new DomainException('Request receipt belongs to another operation.');$this->db->commit();return['version'=>(int)$p['version'],'duplicate'=>true,'outlet'=>$o];}
            if((string)$c['version']!==$expected)throw new DomainException('Outlet setup changed. Reload before saving.');
            $life=$platform?(new Entitlements($this->db,$this->auth))->platformLifecycleForTransaction($m):((new Entitlements($this->db,$this->auth))->overview($m,$o)['lifecycle']);
            $allowed=$life&&in_array($life['state'],['TRIAL','ACTIVE','GRACE'],true)?json_decode($life['assignment']['features_json'],true):[];
            $profile=$operation==='save'?$data['profile']:self::profile(json_decode($c['profile_json'],true));$modules=$operation==='save'?$data['modules']:self::modules(json_decode($c['modules_json'],true));
            if(!$modules||array_diff($modules,$allowed))throw new DomainException('Assign a current subscription covering all selected modules before saving or reviewing.');
            $removed=array_diff(json_decode($c['modules_json'],true),$modules);if(array_intersect($removed,['pos','kitchen','floor','reservations'])&&PlatformAccess::hasWork($this->db,$m,$o))throw new DomainException('Complete active services, kitchen tickets and reservations before disabling operational modules.');
            $v=(int)$expected+1;$reviewed=$operation==='review'?1:0;
            $this->rows('UPDATE merchant_outlet_configuration SET version=?,profile_json=?,modules_json=?,reviewed=?,updated_by=?,updated_epoch=? WHERE merchant_id=? AND outlet_id=?',[$v,json_encode($profile,JSON_THROW_ON_ERROR),json_encode($modules),$reviewed,$actor,time(),$m,$o]);
            $details=['profile'=>$profile,'modules'=>$modules,'reviewed'=>(bool)$reviewed];$this->rows('INSERT INTO merchant_integration_events VALUES(?,?,?,?,?,?,?,?,?,?)',[$request,$m,$o,$v,$actor,$operation,$hash,json_encode($details,JSON_THROW_ON_ERROR),$reason,time()]);
            $this->db->commit();return['version'=>$v,'outlet'=>$o,'reviewed'=>(bool)$reviewed,'duplicate'=>false];
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw$e;}
    }
    public function outlets(string $m,bool $platform):array
    {
        $this->gate();Identity::validate($m);
        if($platform){$native=(new PlatformAdministration($this->db,$this->auth))->detail($m);return['merchant'=>$m,'name'=>$native['merchant']['name'],'outlets'=>$native['outlets'],'platform'=>true,'native'=>$native];}
        $actor=$this->auth->currentUserId();if(!$actor)throw new DomainException('Authentication required.');
        $org=$this->rows('SELECT m.name FROM merchant_organizations m JOIN merchant_memberships r ON r.merchant_id=m.merchant_id AND r.user_id=? AND r.active=1 AND r.role_code=\'merchant_owner\' WHERE m.merchant_id=? AND m.active=1',[$actor,$m]);if(!$org)throw new DomainException('Merchant owner outlet directory required.');
        return['merchant'=>$m,'name'=>$org[0]['name'],'platform'=>false,'outlets'=>$this->rows('SELECT o.outlet_id,o.name,c.version,c.reviewed,l.legacy_outlet_id,s.manual_enabled,s.opening_time,s.closing_time FROM merchant_outlets o JOIN merchant_legacy_outlets l ON l.merchant_id=o.merchant_id AND l.merchant_outlet_id=o.outlet_id JOIN shop_operations s ON s.outlet_id=l.legacy_outlet_id LEFT JOIN merchant_outlet_configuration c ON c.merchant_id=o.merchant_id AND c.outlet_id=o.outlet_id WHERE o.merchant_id=? AND o.active=1 ORDER BY o.name',[$m])];
    }
    public function view(string $m,string $o,bool $platform=false):array
    {
        $authority=$this->authority($m,$o,$platform,false);$mapping=$this->rows('SELECT o.name,m.name merchant_name,l.legacy_outlet_id,s.opening_time,s.closing_time,s.manual_enabled FROM merchant_outlets o JOIN merchant_organizations m ON m.merchant_id=o.merchant_id JOIN merchant_legacy_outlets l ON l.merchant_id=o.merchant_id AND l.merchant_outlet_id=o.outlet_id JOIN shop_operations s ON s.outlet_id=l.legacy_outlet_id WHERE o.merchant_id=? AND o.outlet_id=?',[$m,$o]);if(!$mapping)throw new DomainException('Mapped outlet required.');$map=$mapping[0];$legacy=(int)$map['legacy_outlet_id'];$cfg=self::configuration($this->db,$m,$o);
        if($platform){$native=(new PlatformAdministration($this->db,$this->auth))->detail($m);$life=$native['subscription'];}else{$native=null;$life=(new Entitlements($this->db,$this->auth))->overview($m,$o)['lifecycle'];}
        $licensed=$life&&in_array($life['state'],['TRIAL','ACTIVE','GRACE'],true)?json_decode($life['assignment']['features_json'],true):[];$selected=$cfg?json_decode($cfg['modules_json'],true):$licensed;
        $one=fn($sql,$args)=>(int)$this->rows($sql,$args)[0]['n'];
        $categories=$one('SELECT COUNT(*) n FROM categories WHERE outlet_id=? AND active=1',[$legacy]);
        $products=$one('SELECT COUNT(*) n FROM products WHERE outlet_id=? AND active=1',[$legacy]);
        $available=$one("SELECT COUNT(*) n FROM products p JOIN preparation_stations s ON s.outlet_id=p.outlet_id AND s.station_id=p.station_id AND s.active=1 WHERE p.outlet_id=? AND p.active=1 AND p.available=1 AND p.routing_status='approved' AND EXISTS(SELECT 1 FROM product_variants v WHERE v.product_id=p.product_id AND v.active=1 AND v.price_paise>0)",[$legacy]);
        $stations=$one('SELECT COUNT(*) n FROM preparation_stations WHERE outlet_id=? AND active=1',[$legacy]);
        $staff=$one("SELECT COUNT(*) n FROM merchant_setup_staff s JOIN staff_accounts a ON a.account_id=s.account_id AND a.enabled=1 AND a.must_change=0 JOIN merchant_outlet_memberships r ON r.user_id=s.user_id AND r.merchant_id=s.merchant_id AND r.outlet_id=s.merchant_outlet_id AND r.active=1 WHERE s.merchant_id=? AND s.merchant_outlet_id=? AND s.status='active'",[$m,$o]);
        $inventory=$one('SELECT COUNT(*) n FROM merchant_inventory_items WHERE merchant_id=? AND outlet_id=?',[$m,$o]);
        $recipes=$one('SELECT COUNT(*) n FROM merchant_inventory_recipes WHERE merchant_id=? AND outlet_id=?',[$m,$o]);
        $floors=$one('SELECT COUNT(*) n FROM merchant_seating_floors WHERE merchant_id=? AND outlet_id=? AND active=1',[$m,$o]);$tables=$one('SELECT COUNT(*) n FROM merchant_seating_tables WHERE merchant_id=? AND outlet_id=? AND active=1',[$m,$o]);
        $brand=$this->rows('SELECT version,current_asset_id FROM merchant_brand_profiles WHERE merchant_id=? AND merchant_outlet_id=?',[$m,$o]);$business=$this->rows('SELECT business_saved,outlet_saved,progress FROM merchant_owner_setup WHERE merchant_id=?',[$m]);
        $profile=$cfg?json_decode($cfg['profile_json'],true):[];$profileSaved=isset($profile['address'],$profile['contact']);$profileReady=$cfg?$profileSaved:(bool)($business[0]['business_saved']??false)&&(bool)($business[0]['outlet_saved']??false);
        $scope='merchant='.rawurlencode($m).'&outlet='.rawurlencode($o);$cards=[];
        $card=function($id,$label,$ready,$detail,$url,$feature=null,$ownerOnly=false)use(&$cards,$licensed,$selected,$authority,$platform){$enabled=$feature===null||(in_array($feature,$licensed,true)&&in_array($feature,$selected,true));$cards[]=['id'=>$id,'label'=>$label,'ready'=>$ready&&$enabled,'enabled'=>$enabled,'detail'=>$detail,'url'=>$url,'canOpen'=>$enabled&&(!$ownerOnly||$authority['owner'])&&!$platform];};
        $card('profile','Restaurant & outlet profile',$profileReady,$cfg?($profileSaved?'Outlet contact and address saved':'Complete outlet contact and address'):'Existing native merchant profile','outlet_wizard=1&'.$scope,null,true);
        $card('menu','Menu & product availability',$available>0,$products.' active products · '.$available.' available and routed · '.$categories.' categories','restaurant=1&section=menu&'.$scope,'menu',true);
        $card('kitchen','Kitchen stations',$stations>0,$stations.' active approved station records','restaurant=1&section=kitchen&'.$scope,'kitchen',true);
        $card('staff','Staff assignments',$staff>0,$staff.' active outlet staff; original merchant owner remains protected','restaurant=1&section=staff&'.$scope,'staff',true);
        $card('inventory','Inventory',$inventory>0,$inventory.' saved ingredients; no automatic stock deduction','inventory=1&'.$scope,'inventory',true);
        $card('recipes','Recipes',$recipes>0,$recipes.' saved recipe revisions','inventory=1&'.$scope,'recipes',true);
        $card('floor','Floors, tables & reservations',$floors>0&&$tables>0,$floors.' active floors · '.$tables.' active tables','seating=1&'.$scope,'floor');
        $card('subscription','Subscription overview',(bool)$licensed,$life['state']??'Assign a subscription','subscription=1&'.$scope,null,true);
        $card('branding','Outlet branding',isset($brand[0])&&(int)$brand[0]['version']>1,isset($brand[0])?'Authorized outlet draft; logo '.($brand[0]['current_asset_id']?'retained':'not supplied'):'No saved outlet branding yet','brand=1&'.$scope,'branding',true);
        $posReady=$available>0&&$stations>0&&$tables>0&&(!$cfg||(bool)$cfg['reviewed'])&&PlatformAccess::canStart($this->db,$m,$o);
        $card('pos','Internal POS readiness',$posReady,$posReady?'Configured for authorized nonfinancial internal rehearsal':'Requires available routed products, kitchen, tables and reviewed configuration','pos=1&'.$scope,'pos');
        $card('analytics','Operational analytics',PlatformAccess::canStart($this->db,$m,$o),'Read-only actual events; no invented commercial revenue','analytics=1&'.$scope,'analytics',true);
        return['merchant'=>$m,'outlet'=>$o,'mapping'=>$map,'platform'=>$platform,'owner'=>$authority['owner'],'configuration'=>$cfg,'profile'=>$profile,'licensed'=>$licensed,'selected'=>$selected,'lifecycle'=>$life,'native'=>$native,'cards'=>$cards,'posReady'=>$posReady,'outlets'=>$platform?($native['outlets']??[]):($authority['owner']?$this->outlets($m,false)['outlets']:[['outlet_id'=>$o,'name'=>$map['name']]]),'history'=>$this->rows('SELECT version,operation,reason,created_epoch,actor_id FROM merchant_integration_events WHERE merchant_id=? AND outlet_id=? ORDER BY version DESC LIMIT 25',[$m,$o])];
    }
}
