<?php
declare(strict_types=1);
namespace Kios\MerchantOperations;
use DomainException;
use InvalidArgumentException;
use LogicException;
use PDO;
use Throwable;
use Kios\Infrastructure\ShopOperations;
use Kios\MerchantFoundation\{AuthenticatedUserProvider,AuthorizationService,Identity,PdoIdentityStore,Permission};

/** Draft setup only. Never opens ordering, grants roles or verifies legal claims. */
final class OwnerOnboarding
{
    public function __construct(private readonly PDO $db,private readonly AuthenticatedUserProvider $authentication){
        if($db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql')throw new LogicException('MariaDB required.');
        $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);
    }
    private function rows(string $sql,array $args=[]):array{$q=$this->db->prepare($sql);$q->execute($args);return $q->fetchAll(PDO::FETCH_ASSOC);}
    public function home():?array
    {
        $user=$this->authentication->currentUserId();if($user===null)return null;
        $r=$this->rows("SELECT p.merchant_id,p.merchant_outlet_id FROM merchant_onboarding p
            JOIN merchant_memberships m ON m.merchant_id=p.merchant_id AND m.user_id=p.owner_user_id AND m.active=1
            JOIN merchant_organizations o ON o.merchant_id=p.merchant_id AND o.active=1
            WHERE p.owner_user_id=? AND p.status='active'",[$user]);return count($r)===1?$r[0]:null;
    }
    private function authorized(string $merchant,bool $platform,callable $work):mixed
    {
        Identity::validate($merchant);if($this->db->inTransaction())throw new LogicException('Setup owns its transaction.');
        $auth=new AuthorizationService($this->authentication,new PdoIdentityStore($this->db));
        if($platform){$auth->requirePlatformAdministration();$actor=$this->authentication->currentUserId();}
        else{
            $mapping=$this->rows('SELECT merchant_outlet_id FROM merchant_onboarding WHERE merchant_id=?',[$merchant]);
            if(!$mapping)throw new DomainException('Provisioned owner setup required.');
            $actor=$auth->requireResource(Permission::ManageMerchant,$merchant,$mapping[0]['merchant_outlet_id'],$merchant,$mapping[0]['merchant_outlet_id'])->userId;
        }
        if($actor===null)throw new DomainException('Authentication required.');
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$this->db->beginTransaction();
        try{
            if($this->authentication->currentUserId()!==$actor||!$this->rows('SELECT user_id FROM merchant_staff WHERE user_id=? AND active=1 FOR UPDATE',[$actor]))throw new DomainException('Current setup identity required.');
            if($platform && (!$this->rows("SELECT user_id FROM merchant_platform_memberships WHERE user_id=? AND role_code='platform_administrator' AND active=1 FOR UPDATE",[$actor])
                || !$this->rows("SELECT role_code FROM merchant_role_permissions WHERE role_code='platform_administrator' AND permission_code='manage_platform' FOR UPDATE")))throw new DomainException('Platform review denied.');
            $r=$this->rows("SELECT p.*,o.name AS initial_business,u.display_name AS owner_name,s.opening_time,s.closing_time,s.manual_enabled,
                mo.name AS initial_outlet FROM merchant_onboarding p
                JOIN merchant_organizations o ON o.merchant_id=p.merchant_id AND o.active=1
                JOIN merchant_outlets mo ON mo.merchant_id=p.merchant_id AND mo.outlet_id=p.merchant_outlet_id AND mo.active=1
                JOIN merchant_legacy_outlets l ON l.merchant_id=p.merchant_id AND l.merchant_outlet_id=p.merchant_outlet_id AND l.legacy_outlet_id=p.legacy_outlet_id
                JOIN outlets lo ON lo.outlet_id=p.legacy_outlet_id AND lo.active=1
                JOIN merchant_staff u ON u.user_id=p.owner_user_id AND u.active=1
                JOIN staff_accounts a ON a.account_id=p.owner_account_id AND a.outlet_id=p.legacy_outlet_id AND a.enabled=1 AND a.must_change=0
                JOIN merchant_auth_links al ON al.account_id=p.owner_account_id AND al.user_id=p.owner_user_id AND al.legacy_outlet_id=p.legacy_outlet_id AND al.active=1
                JOIN shop_operations s ON s.outlet_id=p.legacy_outlet_id
                WHERE p.merchant_id=? AND p.status='active' FOR UPDATE",[$merchant]);
            if(count($r)!==1)throw new DomainException('Activation and current owner account are required.');$p=$r[0];
            if($platform){if($p['created_by']!==$actor)throw new DomainException('Creator administrator review required.');}
            elseif($p['owner_user_id']!==$actor||!$this->rows("SELECT user_id FROM merchant_memberships WHERE user_id=? AND merchant_id=? AND role_code='merchant_owner' AND active=1 FOR UPDATE",[$actor,$merchant]))throw new DomainException('Current merchant owner required.');
            if(!$this->rows('SELECT merchant_id FROM merchant_owner_setup WHERE merchant_id=? FOR UPDATE',[$merchant])){
                $business=['business_name'=>$p['initial_business'],'category'=>$p['business_type'],'admin_contact'=>$p['admin_contact'],'description'=>'','location'=>'','registration_id'=>'','operating_status'=>'planning'];
                $outlet=['outlet_name'=>$p['initial_outlet'],'contact'=>$p['admin_contact'],'address'=>'','days'=>['mon','tue','wed','thu','fri','sat','sun'],'opening_time'=>$p['opening_time'],'closing_time'=>$p['closing_time'],'temporary_preference'=>'closed'];
                $this->rows('INSERT INTO merchant_owner_setup(merchant_id,merchant_outlet_id,legacy_outlet_id,owner_user_id,business_draft,outlet_draft,created_epoch,initialized_by,updated_epoch) VALUES(?,?,?,?,?,?,?,?,?)',[$merchant,$p['merchant_outlet_id'],$p['legacy_outlet_id'],$p['owner_user_id'],json_encode($business,JSON_THROW_ON_ERROR),json_encode($outlet,JSON_THROW_ON_ERROR),time(),$actor,time()]);
            }
            $state=$this->rows('SELECT * FROM merchant_owner_setup WHERE merchant_id=? FOR UPDATE',[$merchant])[0];
            $result=$work($state,$p,$actor);$this->db->commit();return $result;
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    private function model(array $s,array $p):array
    {
        $s['business']=json_decode($s['business_draft'],true,32,JSON_THROW_ON_ERROR);$s['outlet']=json_decode($s['outlet_draft'],true,32,JSON_THROW_ON_ERROR);unset($s['business_draft'],$s['outlet_draft']);
        $s['owner_name']=$p['owner_name'];$s['ordering_open']=(new ShopOperations($this->db))->state((int)$p['legacy_outlet_id'])['open'];return $s;
    }
    public function load(string $merchant,bool $platform=false):array{return $this->authorized($merchant,$platform,fn($s,$p)=>$this->model($s,$p));}
    private function text(mixed $value,int $max,bool $required=true):string
    {
        if(!is_string($value)||!mb_check_encoding($value,'UTF-8')||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u',$value)||mb_strlen(trim($value),'UTF-8')>$max||($required&&trim($value)===''))throw new InvalidArgumentException('Please complete the required fields within their size limits.');return trim($value);
    }
    private function exact(array $input,array $keys):void{if(array_diff(array_keys($input),$keys)||array_diff($keys,array_keys($input)))throw new InvalidArgumentException('Only the displayed editable setup fields are accepted. Approval, identity and permission fields cannot be edited.');}
    private function business(array $input):array
    {
        $this->exact($input,['business_name','category','admin_contact','description','location','registration_id','operating_status']);
        $r=[];foreach(['business_name'=>200,'category'=>24,'admin_contact'=>254,'description'=>1000,'location'=>500,'registration_id'=>100,'operating_status'=>24] as $k=>$max)$r[$k]=$this->text($input[$k],$max,!in_array($k,['description','registration_id'],true));
        if(!in_array($r['category'],['restaurant','cafe'],true)||!in_array($r['operating_status'],['planning','operating','temporarily_closed'],true))throw new InvalidArgumentException('Choose a supported business category and operating preference.');
        $r['admin_contact']=strtolower($r['admin_contact']);if(!filter_var($r['admin_contact'],FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Enter a valid administrative email address.');return $r;
    }
    private function outlet(array $input):array
    {
        $this->exact($input,['outlet_name','contact','address','days','opening_time','closing_time','temporary_preference']);$r=[];
        foreach(['outlet_name'=>160,'contact'=>120,'address'=>500,'opening_time'=>5,'closing_time'=>5,'temporary_preference'=>24] as $k=>$max)$r[$k]=$this->text($input[$k],$max);
        if(!filter_var($r['contact'],FILTER_VALIDATE_EMAIL)&&preg_match('/^[+0-9 ()-]{6,30}$/D',$r['contact'])!==1)throw new InvalidArgumentException('Enter a valid outlet email address or phone number.');
        $days=['mon','tue','wed','thu','fri','sat','sun'];$given=$input['days'];
        if(!is_array($given)||!array_is_list($given)||count($given)<1||count($given)>7||count(array_unique($given,SORT_REGULAR))!==count($given))throw new InvalidArgumentException('Select one or more unique operating days.');
        foreach($given as $day)if(!is_string($day)||!in_array($day,$days,true))throw new InvalidArgumentException('Select valid operating days.');
        $r['days']=array_values(array_intersect($days,$given));
        foreach(['opening_time','closing_time'] as $k)if(preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$r[$k])!==1)throw new InvalidArgumentException('Use valid 24-hour opening and closing times.');
        if($r['opening_time']>=$r['closing_time']||!in_array($r['temporary_preference'],['closed','prefer_open'],true))throw new InvalidArgumentException('Closing must follow opening; choose a valid temporary preference.');return $r;
    }
    /** All progress targets are server-selected. Idempotency is bound to scope, actor and full request. */
    public function act(string $merchant,string $outlet,int $version,string $request,string $operation,array $input=[]):array
    {
        Identity::validate($outlet);if($version<1||preg_match('/^[a-f0-9]{32}$/D',$request)!==1||!in_array($operation,['save_business','save_outlet','submit','reopen','review'],true))throw new InvalidArgumentException('Invalid setup action.');
        $data=match($operation){'save_business'=>$this->business($input),'save_outlet'=>$this->outlet($input),'review'=>$this->reviewInput($input),default=>$input};
        if(in_array($operation,['submit','reopen'],true)&&$data!==[])throw new InvalidArgumentException('This action does not accept status or permission fields.');
        $hash=hash('sha256',json_encode([$merchant,$outlet,$version,$operation,$data],JSON_THROW_ON_ERROR));
        return $this->authorized($merchant,$operation==='review',function($s,$p,$actor)use($merchant,$outlet,$version,$request,$operation,$data,$hash):array{
            if($p['merchant_outlet_id']!==$outlet)throw new DomainException('Outlet does not belong to this setup.');
            $prior=$this->rows('SELECT * FROM merchant_setup_events WHERE request_id=? FOR UPDATE',[$request]);
            if($prior){if($prior[0]['merchant_id']!==$merchant||$prior[0]['actor_user_id']!==$actor||!hash_equals($prior[0]['request_hash'],$hash))throw new DomainException('Submission identifier belongs to a different request.');return ['duplicate'=>true,'appliedVersion'=>(int)$prior[0]['result_version'],'state'=>$this->model($s,$p)];}
            if((int)$s['version']!==$version)throw new DomainException('Settings changed in another tab. Reload before saving.');
            $from=$s['progress'];$to=$from;$business=$s['business_draft'];$outletDraft=$s['outlet_draft'];$bs=(int)$s['business_saved'];$os=(int)$s['outlet_saved'];$submitted=$s['submitted_epoch'];$reviewer=$s['reviewed_by'];$reviewed=$s['reviewed_epoch'];$note=$s['review_note'];
            if($operation==='review'){
                if($from!=='APPROVAL_PENDING')throw new DomainException('Only a submitted setup can be reviewed.');
                $to=$data['decision'];$note=$data['note'];$reviewer=$actor;$reviewed=time();
            }elseif($operation==='reopen'){
                if(!in_array($from,['APPROVAL_PENDING','APPROVED','REJECTED'],true))throw new DomainException('No submitted or reviewed setup to reopen.');$to='IN_PROGRESS';$submitted=null;
            }else{
                if(!in_array($from,['NOT_STARTED','IN_PROGRESS','SETUP_COMPLETE'],true))throw new DomainException('Reopen setup before making changes.');
                if($operation==='save_business'){$business=json_encode($data,JSON_THROW_ON_ERROR);$bs=1;}
                elseif($operation==='save_outlet'){$outletDraft=json_encode($data,JSON_THROW_ON_ERROR);$os=1;}
                else{if($from!=='SETUP_COMPLETE'||!$bs||!$os)throw new DomainException('Save business and outlet details before submitting.');$to='APPROVAL_PENDING';$submitted=time();}
                if($operation!=='submit')$to=$bs&&$os?'SETUP_COMPLETE':'IN_PROGRESS';
            }
            $this->rows('UPDATE merchant_owner_setup SET business_draft=?,outlet_draft=?,business_saved=?,outlet_saved=?,progress=?,version=version+1,updated_epoch=?,submitted_epoch=?,reviewed_by=?,reviewed_epoch=?,review_note=? WHERE merchant_id=? AND version=?',[$business,$outletDraft,$bs,$os,$to,time(),$submitted,$reviewer,$reviewed,$note,$merchant,$version]);
            $this->rows('INSERT INTO merchant_setup_events(request_id,merchant_id,actor_user_id,operation,request_hash,from_status,to_status,result_version,occurred_epoch) VALUES(?,?,?,?,?,?,?,?,?)',[$request,$merchant,$actor,$operation,$hash,$from,$to,$version+1,time()]);
            $s=$this->rows('SELECT * FROM merchant_owner_setup WHERE merchant_id=?',[$merchant])[0];return ['duplicate'=>false,'appliedVersion'=>$version+1,'state'=>$this->model($s,$p)];
        });
    }
    private function reviewInput(array $input):array
    {
        $this->exact($input,['decision','note']);$decision=$this->text($input['decision'],24);$note=$this->text($input['note'],500,$decision==='REJECTED');
        if(!in_array($decision,['APPROVED','REJECTED'],true)||($decision==='REJECTED'&&mb_strlen($note)<8))throw new InvalidArgumentException('Choose approval or rejection and provide a useful correction note for rejection.');return ['decision'=>$decision,'note'=>$note];
    }
}
