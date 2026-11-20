<?php
declare(strict_types=1);
namespace Kios\MerchantOperations;
use DomainException;
use InvalidArgumentException;
use LogicException;
use PDO;
use Throwable;
use Kios\Infrastructure\StaffPasswords;
use Kios\MerchantFoundation\{AuthenticatedUserProvider,AuthorizationService,Identity,PdoIdentityStore};

/** Internal, administrator-attended provisioning only. No registration/transfer/recovery API. */
final class MerchantProvisioner
{
    private bool $ownsTransaction=false;
    public function __construct(private readonly PDO $db,private readonly AuthenticatedUserProvider $authentication,private readonly ?\Closure $observer=null)
    {
        if($db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql')throw new LogicException('MariaDB required.');
        $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);
    }
    private function rows(string $sql,array $values=[]):array {$q=$this->db->prepare($sql);$q->execute($values);return $q->fetchAll(PDO::FETCH_ASSOC);}
    public function assertAdministrator():string
    {
        if($this->db->inTransaction())throw new LogicException('Provisioning owns its transaction.');
        (new AuthorizationService($this->authentication,new PdoIdentityStore($this->db)))->requirePlatformAdministration();
        $actor=$this->authentication->currentUserId();if($actor===null)throw new DomainException('Administrator authentication required.');return $actor;
    }
    private function transaction(callable $work):mixed
    {
        $actor=$this->assertAdministrator();
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$this->db->beginTransaction();$this->ownsTransaction=true;
        try {
            if($this->authentication->currentUserId()!==$actor
                || !$this->rows('SELECT user_id FROM merchant_staff WHERE user_id=? AND active=1 FOR UPDATE',[$actor])
                || !$this->rows("SELECT user_id FROM merchant_platform_memberships WHERE user_id=? AND active=1 AND role_code='platform_administrator' FOR UPDATE",[$actor])
                || !$this->rows("SELECT role_code FROM merchant_role_permissions WHERE role_code='platform_administrator' AND permission_code='manage_platform' FOR UPDATE"))throw new DomainException('Current platform administrator permission required.');
            $result=$work($actor);if($this->observer)($this->observer)($this->db,$actor,$result);$this->db->commit();return $result;
        }catch(Throwable $error){if($this->db->inTransaction())$this->db->rollBack();throw $error;}finally{$this->ownsTransaction=false;}
    }
    /** Trusted transaction port; every caller still passes current native platform authorization. */
    public function administer(callable $work):mixed{return $this->transaction($work);}
    private function outletRecords(string $merchant,string $outlet,string $name,string $opening,string $closing,bool $active):int
    {
        $this->rows('INSERT INTO merchant_outlets VALUES(?,?,?,?)',[$outlet,$merchant,$name,(int)$active]);
        $this->rows('INSERT INTO outlets(outlet_code,name,active) VALUES(?,?,?)',['merchant-dev-'.str_replace('-','',$outlet),$name,(int)$active]);$legacy=(int)$this->db->lastInsertId();
        $this->rows('INSERT INTO merchant_legacy_outlets VALUES(?,?,?)',[$merchant,$outlet,$legacy]);
        $this->rows('INSERT INTO shop_operations(outlet_id,opening_time,closing_time) VALUES(?,?,?)',[$legacy,$opening,$closing]);
        return $legacy;
    }
    /** Reuse the M02 native outlet/mapping/CLOSED-shop factory inside its authorized transaction. */
    public function additionalOutlet(string $merchant,string $name,string $opening,string $closing):array
    {
        if(!$this->ownsTransaction||!$this->db->inTransaction())throw new LogicException('Native provisioning transaction required.');
        Identity::validate($merchant);if(!$this->rows('SELECT merchant_id FROM merchant_organizations WHERE merchant_id=? AND active=1 FOR UPDATE',[$merchant]))throw new DomainException('Active merchant required.');
        $outlet=Identity::generate();$legacy=$this->outletRecords($merchant,$outlet,$name,$opening,$closing,true);return ['outlet'=>$outlet,'loginOutlet'=>$legacy];
    }
    private function request(string $request):void {if(preg_match('/^[a-f0-9]{32}$/D',$request)!==1)throw new InvalidArgumentException('Use a valid submission identifier.');}
    private function input(array $input):array
    {
        $limits=['business_name'=>200,'business_type'=>24,'admin_contact'=>254,'outlet_name'=>160,'owner_name'=>100,'owner_username'=>64,'opening_time'=>5,'closing_time'=>5];
        if(array_diff(array_keys($input),array_keys($limits)) || array_diff(array_keys($limits),array_keys($input)))throw new InvalidArgumentException('Supply only the required business, outlet and owner details; identities and roles are server-owned.');
        $clean=[];foreach($limits as $key=>$max){$v=$input[$key];if(!is_string($v)||!mb_check_encoding($v,'UTF-8')||preg_match('/[\x00-\x1f\x7f]/u',$v)||trim($v)===''||mb_strlen(trim($v),'UTF-8')>$max)throw new InvalidArgumentException('Required details must be readable, nonempty and within their field limits.');$clean[$key]=trim($v);}
        if(!in_array($clean['business_type'],['restaurant','cafe'],true))throw new InvalidArgumentException('Select restaurant or cafe.');
        $clean['admin_contact']=strtolower($clean['admin_contact']);if(!filter_var($clean['admin_contact'],FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Enter a valid administrative email address; no email will be sent.');
        $clean['owner_username']=strtolower($clean['owner_username']);if(preg_match('/^[a-z0-9][a-z0-9._-]{2,63}$/D',$clean['owner_username'])!==1)throw new InvalidArgumentException('Use a 3–64 character owner username with letters, numbers, dot, dash or underscore.');
        foreach(['opening_time','closing_time'] as $key)if(preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$clean[$key])!==1)throw new InvalidArgumentException('Use valid 24-hour operating times.');
        if($clean['opening_time']>=$clean['closing_time'])throw new InvalidArgumentException('Closing time must follow opening time on the same day.');
        return $clean;
    }
    private function event(string $merchant,string $actor,string $operation,string $request):void
    {
        $this->rows('INSERT INTO merchant_provisioning_events(merchant_id,actor_user_id,operation,request_id,occurred_epoch) VALUES(?,?,?,?,?)',[$merchant,$actor,$operation,$request,time()]);
    }
    private function record(string $id,string $actor):array
    {
        $r=$this->rows('SELECT * FROM merchant_onboarding WHERE merchant_id=? AND created_by=? FOR UPDATE',[$id,$actor]);
        if(count($r)!==1)throw new DomainException('Provisioning confirmation is not available to this administrator.');return $r[0];
    }
    private function confirmation(array $record):array
    {
        $r=$this->rows('SELECT m.name AS business_name,o.name AS outlet_name,s.display_name AS owner_name,a.username AS owner_username,
            l.active AS outlet_active,so.opening_time,so.closing_time,so.manual_enabled,so.day_ended
            FROM merchant_organizations m JOIN merchant_outlets o ON o.merchant_id=m.merchant_id AND o.outlet_id=?
            JOIN outlets l ON l.outlet_id=? JOIN merchant_staff s ON s.user_id=?
            JOIN staff_accounts a ON a.account_id=? AND a.outlet_id=l.outlet_id
            JOIN shop_operations so ON so.outlet_id=l.outlet_id WHERE m.merchant_id=?',[$record['merchant_outlet_id'],$record['legacy_outlet_id'],$record['owner_user_id'],$record['owner_account_id'],$record['merchant_id']]);
        if(count($r)!==1)throw new DomainException('Provisioning relationships need review.');
        return array_merge($r[0],array_intersect_key($record,array_flip(['merchant_id','merchant_outlet_id','legacy_outlet_id','owner_user_id','business_type','admin_contact','status','version','created_epoch','created_by','activated_epoch'])));
    }
    public function receipt(string $merchant):array
    {
        Identity::validate($merchant);return $this->transaction(fn(string $actor)=>$this->confirmation($this->record($merchant,$actor)));
    }
    public function provision(string $request,array $input):array
    {
        $this->request($request);$clean=$this->input($input);$hash=hash('sha256',json_encode($clean,JSON_THROW_ON_ERROR));
        return $this->transaction(function(string $actor)use($request,$clean,$hash):array {
            $prior=$this->rows('SELECT * FROM merchant_onboarding WHERE request_id=? FOR UPDATE',[$request]);
            if($prior){if($prior[0]['created_by']!==$actor||!hash_equals($prior[0]['request_hash'],$hash))throw new DomainException('Submission identifier already belongs to another request.');return ['duplicate'=>true,'receipt'=>$this->confirmation($prior[0])];}
            $count=$this->rows('SELECT COUNT(*) AS count FROM merchant_onboarding WHERE created_by=? AND created_epoch>=?',[$actor,time()-3600]);
            if((int)$count[0]['count']>=10)throw new DomainException('Internal provisioning limit reached. Wait for the review window.');
            $merchant=Identity::generate();$outlet=Identity::generate();$user=Identity::generate();$account=bin2hex(random_bytes(16));$now=time();
            $this->rows('INSERT INTO merchant_organizations VALUES(?,?,0)',[$merchant,$clean['business_name']]);
            $legacy=$this->outletRecords($merchant,$outlet,$clean['outlet_name'],$clean['opening_time'],$clean['closing_time'],false);
            $this->rows('INSERT INTO merchant_staff VALUES(?,?,0)',[$user,$clean['owner_name']]);
            // Native staff role is a login transport only; foundation membership below owns authorization.
            // No temporary plaintext password is handed out or persisted.
            $this->rows("INSERT INTO staff_accounts(account_id,outlet_id,realm,username,display_name,role,password_hash,enabled,must_change,auth_version,created_at,updated_at)
                VALUES(?,?,'staff',?,?,'cashier',?,0,1,1,?,?)",[$account,$legacy,$clean['owner_username'],$clean['owner_name'],StaffPasswords::hash(StaffPasswords::generate()),$now,$now]);
            $this->rows('INSERT INTO merchant_auth_links VALUES(?,?,?,0)',[$account,$user,$legacy]);
            $this->rows("INSERT INTO merchant_memberships VALUES(?,?,'merchant_owner',0)",[$user,$merchant]);
            $this->rows("INSERT INTO merchant_onboarding(merchant_id,merchant_outlet_id,legacy_outlet_id,owner_user_id,owner_account_id,business_type,admin_contact,status,created_by,created_epoch,request_id,request_hash)
                VALUES(?,?,?,?,?,?,?,'pending_activation',?,?,?,?)",[$merchant,$outlet,$legacy,$user,$account,$clean['business_type'],$clean['admin_contact'],$actor,$now,$request,$hash]);
            $this->event($merchant,$actor,'provisioned',$request);
            return ['duplicate'=>false,'receipt'=>$this->confirmation($this->record($merchant,$actor))];
        });
    }
    public function activate(string $merchant,int $version,string $request,string $password,string $confirmation):array
    {
        Identity::validate($merchant);$this->request($request);if($version<1)throw new InvalidArgumentException('Reload the current confirmation.');
        if(!hash_equals($password,$confirmation))throw new DomainException('The new passwords do not match.');StaffPasswords::validate($password);
        return $this->transaction(function(string $actor)use($merchant,$version,$request,$password):array {
            $record=$this->record($merchant,$actor);
            $accounts=$this->rows('SELECT * FROM staff_accounts WHERE account_id=? AND outlet_id=? FOR UPDATE',[$record['owner_account_id'],$record['legacy_outlet_id']]);
            if(count($accounts)!==1)throw new DomainException('Owner account relationship changed.');$account=$accounts[0];
            if($record['status']==='active'){
                if($record['activation_request_id']!==$request||$record['activated_by']!==$actor||(int)$record['version']!==$version+1
                    || (int)$account['enabled']!==1 || (int)$account['must_change']!==0
                    || !StaffPasswords::verifies($password,$account['password_hash']))throw new DomainException('Activation already completed; no password was changed.');
                return ['duplicate'=>true,'receipt'=>$this->confirmation($record)];
            }
            if((int)$record['version']!==$version)throw new DomainException('Provisioning changed. Reload before activation.');
            $count=$this->rows("SELECT COUNT(*) AS count FROM merchant_provisioning_events WHERE actor_user_id=? AND operation='activated' AND occurred_epoch>=?",[$actor,time()-3600]);
            if((int)$count[0]['count']>=50)throw new DomainException('Internal activation limit reached.');
            $user=$record['owner_user_id'];$legacy=(int)$record['legacy_outlet_id'];$outlet=$record['merchant_outlet_id'];
            $membership=$this->rows('SELECT * FROM merchant_memberships WHERE user_id=? FOR UPDATE',[$user]);
            if(count($membership)!==1||$membership[0]['merchant_id']!==$merchant||$membership[0]['role_code']!=='merchant_owner'||(int)$membership[0]['active']!==0
                || $this->rows('SELECT user_id FROM merchant_platform_memberships WHERE user_id=? FOR UPDATE',[$user])
                || $this->rows('SELECT user_id FROM merchant_outlet_memberships WHERE user_id=? FOR UPDATE',[$user])
                || $this->rows('SELECT user_id FROM merchant_financial_grants WHERE user_id=? FOR UPDATE',[$user])
                || $this->rows('SELECT actor_user_id FROM merchant_worker_grants WHERE actor_user_id=? FOR UPDATE',[$user]))throw new DomainException('Unexpected owner privileges; activation denied.');
            foreach([
                ['SELECT merchant_id FROM merchant_organizations WHERE merchant_id=? AND active=0 FOR UPDATE',[$merchant]],
                ['SELECT outlet_id FROM merchant_outlets WHERE merchant_id=? AND outlet_id=? AND active=0 FOR UPDATE',[$merchant,$outlet]],
                ['SELECT outlet_id FROM outlets WHERE outlet_id=? AND active=0 FOR UPDATE',[$legacy]],
                ['SELECT user_id FROM merchant_staff WHERE user_id=? AND active=0 FOR UPDATE',[$user]],
                ['SELECT user_id FROM merchant_auth_links WHERE account_id=? AND user_id=? AND legacy_outlet_id=? AND active=0 FOR UPDATE',[$record['owner_account_id'],$user,$legacy]],
            ] as [$sql,$values])if(count($this->rows($sql,$values))!==1)throw new DomainException('Pending owner relationships changed.');
            if((int)$account['enabled']!==0||(int)$account['must_change']!==1||$account['realm']!=='staff'||$account['role']!=='cashier')throw new DomainException('Pending account changed.');
            $this->rows('UPDATE staff_accounts SET password_hash=?,enabled=1,must_change=0,auth_version=auth_version+1,updated_at=? WHERE account_id=?',[StaffPasswords::hash($password),time(),$record['owner_account_id']]);
            $this->rows('UPDATE merchant_organizations SET active=1 WHERE merchant_id=?',[$merchant]);
            $this->rows('UPDATE merchant_outlets SET active=1 WHERE merchant_id=? AND outlet_id=?',[$merchant,$outlet]);
            $this->rows('UPDATE outlets SET active=1 WHERE outlet_id=?',[$legacy]);$this->rows('UPDATE merchant_staff SET active=1 WHERE user_id=?',[$user]);
            $this->rows('UPDATE merchant_auth_links SET active=1 WHERE account_id=?',[$record['owner_account_id']]);
            $this->rows('UPDATE merchant_memberships SET active=1 WHERE user_id=? AND merchant_id=?',[$user,$merchant]);
            $this->rows("UPDATE merchant_onboarding SET status='active',version=version+1,activated_by=?,activated_epoch=?,activation_request_id=? WHERE merchant_id=?",[$actor,time(),$request,$merchant]);
            $this->event($merchant,$actor,'activated',$request);
            return ['duplicate'=>false,'receipt'=>$this->confirmation($this->record($merchant,$actor))];
        });
    }
}
