<?php
declare(strict_types=1);
namespace Kios\Infrastructure;

use Kios\Domain\StaffRole;
use PDO;
use Throwable;
use DomainException;

/** No method returns stored hashes; session arrays must come from server-side PHP sessions. */
final class StaffAccountService
{
    private readonly int $outlet;

    public function __construct(private readonly PDO $db, string $outletCode = 'demo-outlet', private readonly string $rateKey = '')
    {
        $q = $db->prepare('SELECT outlet_id FROM outlets WHERE outlet_code=? AND active=1');
        $q->execute([$outletCode]);
        $id = $q->fetchColumn();
        if ($id === false) { throw new DomainException('The outlet is unavailable.'); }
        $this->outlet = (int)$id;
    }

    public static function enabled(): bool { return Environment::get('KIOS_ACCOUNT_AUTH_ENABLED', '0') === '1'; }

    private function transaction(callable $operation): mixed
    {
        if ($this->db->inTransaction()) { throw new \LogicException('Nested authentication transaction.'); }
        $this->db->beginTransaction();
        try { $r = $operation(); $this->db->commit(); return $r; }
        catch (Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }

    private function lock(): string { return $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''; }

    private function row(string $id, bool $lock = false): ?array
    {
        $q=$this->db->prepare('SELECT * FROM staff_accounts WHERE account_id=? AND outlet_id=?'.($lock?$this->lock():''));
        $q->execute([$id,$this->outlet]); return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function publicRow(array $r): array { unset($r['password_hash']); return $r; }

    private static function operatorActor(): string
    {
        $name=function_exists('posix_geteuid')?(posix_getpwuid(posix_geteuid())['name']??''):get_current_user();
        return 'cli:'.(is_string($name)&&preg_match('/\A[A-Za-z0-9._-]{1,64}\z/',$name)?$name:'local-operator');
    }

    private function audit(string $actor, ?string $target, string $action): void
    {
        $q=$this->db->prepare('INSERT INTO staff_auth_audit (event_id,outlet_id,actor,target_account_id,action,occurred_at) VALUES (?,?,?,?,?,?)');
        $q->execute([bin2hex(random_bytes(16)),$this->outlet,$actor,$target,$action,time()]);
    }

    public function ownerHash(): string
    {
        $q=$this->db->prepare("SELECT password_hash FROM staff_accounts WHERE outlet_id=? AND realm='owner' AND username='owner' AND enabled=1".($this->db->inTransaction()?$this->lock():''));
        $q->execute([$this->outlet]);
        $hash=$q->fetchColumn();
        if (is_string($hash)) return $hash;
        // A provisioned but disabled owner must never fall through to legacy credentials.
        $q=$this->db->prepare("SELECT COUNT(*) FROM staff_accounts WHERE outlet_id=? AND realm='owner'");$q->execute([$this->outlet]);
        if ((int)$q->fetchColumn()>0) return '';
        return Environment::get('KIOS_MENU_ADMIN_PASSWORD_HASH','') ?? '';
    }

    public function authenticateOwner(mixed $candidate,string $peer): ?string
    {
        if(strlen($this->rateKey)<32)throw new \RuntimeException('Account authentication configuration is incomplete.');
        $account=hash_hmac('sha256','owner-account:'.$this->outlet,$this->rateKey);
        $keys=[$account=>5,hash_hmac('sha256','owner-peer:'.$this->outlet.':'.$peer,$this->rateKey)=>20];ksort($keys);
        return $this->transaction(function()use($candidate,$keys,$account){
            $now=time();$rates=[];
            foreach($keys as $bucket=>$limit){
                $sql=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'
                    ? 'INSERT INTO staff_auth_limits (bucket,failures,window_start,locked_until) VALUES (?,0,?,0) ON DUPLICATE KEY UPDATE bucket=VALUES(bucket)'
                    : 'INSERT OR IGNORE INTO staff_auth_limits (bucket,failures,window_start,locked_until) VALUES (?,0,?,0)';
                $this->db->prepare($sql)->execute([$bucket,$now]);
                $q=$this->db->prepare('SELECT * FROM staff_auth_limits WHERE bucket=?'.$this->lock());$q->execute([$bucket]);$r=$q->fetch(PDO::FETCH_ASSOC);
                if((int)$r['locked_until']>$now){$this->audit('owner-login',null,'owner_login_rate_limited');return null;}
                if((int)$r['window_start']<=$now-900){$r['failures']=0;$r['window_start']=$now;}$rates[$bucket]=$r;
            }
            $hash=$this->ownerHash();
            if(!is_string($candidate)||strlen($candidate)>200||str_contains($candidate,"\0")||$hash===''||!password_verify($candidate,$hash)){
                foreach($keys as $bucket=>$limit){$v=$rates[$bucket];$n=(int)$v['failures']+1;$this->db->prepare('UPDATE staff_auth_limits SET failures=?,window_start=?,locked_until=? WHERE bucket=?')->execute([$n,$v['window_start'],$n>=$limit?$now+900:0,$bucket]);}
                $this->audit('owner-login',null,'owner_login_rejected');return null;
            }
            $this->db->prepare('UPDATE staff_auth_limits SET failures=0,window_start=?,locked_until=0 WHERE bucket=?')->execute([$now,$account]);
            $this->audit('owner:menu-admin',null,'owner_login_success');
            // Bind to the exact verified hash, never a later read that could race recovery.
            return hash_hmac('sha256','kios-owner-account-session-v1:'.$this->outlet,$hash);
        });
    }

    public function ownerTag(): string
    {
        $hash=$this->ownerHash();
        return $hash===''?'':hash_hmac('sha256','kios-owner-account-session-v1:'.$this->outlet,$hash);
    }

    public function ownerValid(array $session): bool
    {
        $tag=$this->ownerTag();
        return $tag!=='' && ($session['menu_admin_authenticated']??false)===true
            && ($session['menu_admin_actor']??'')==='menu-admin'
            && is_string($session['_owner_account_tag']??null)
            && hash_equals($tag,$session['_owner_account_tag']);
    }

    private function requireOwner(array $session): string
    {
        if (!$this->ownerValid($session)) throw new DomainException('Owner authorization is required.');
        return 'owner:menu-admin';
    }

    public function listAccounts(array $session): array
    {
        $this->requireOwner($session);
        $q=$this->db->prepare("SELECT account_id,username,display_name,role,enabled,must_change,auth_version FROM staff_accounts WHERE outlet_id=? AND realm='staff' ORDER BY username");
        $q->execute([$this->outlet]);return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function auditHistory(array $session): array
    {
        $this->requireOwner($session);$q=$this->db->prepare('SELECT a.actor,a.target_account_id,a.action,a.occurred_at,t.username AS target_login,u.username AS actor_login FROM staff_auth_audit a LEFT JOIN staff_accounts t ON t.account_id=a.target_account_id AND t.outlet_id=a.outlet_id LEFT JOIN staff_accounts u ON u.account_id=SUBSTR(a.actor,7) AND u.outlet_id=a.outlet_id AND a.actor LIKE \'staff:%\' WHERE a.outlet_id=? ORDER BY a.occurred_at DESC,a.event_id DESC LIMIT 100');
        $q->execute([$this->outlet]);return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create(array $session,string $username,string $name,string $role,bool $enabled=true): array
    {
        $actor=$this->requireOwner($session);$username=strtolower(trim($username));$name=trim($name);
        if (!preg_match('/\A[a-z][a-z0-9._-]{2,63}\z/',$username) || $name==='' || strlen($name)>100 || StaffRole::tryFrom($role)===null)
            throw new DomainException('Enter a valid staff login, name, and operational role.');
        $password=StaffPasswords::generate();$hash=StaffPasswords::hash($password);$id=bin2hex(random_bytes(16));
        $this->transaction(function()use($session,$actor,$username,$name,$role,$enabled,$hash,$id){
            $actor=$this->requireOwner($session);
            $q=$this->db->prepare("INSERT INTO staff_accounts (account_id,outlet_id,realm,username,display_name,role,password_hash,enabled,must_change,auth_version,created_at,updated_at) VALUES (?,?,'staff',?,?,?,?,?,1,1,?,?)");
            $q->execute([$id,$this->outlet,$username,$name,$role,$hash,(int)$enabled,time(),time()]);$this->audit($actor,$id,'create_staff');
        });
        return ['accountId'=>$id,'password'=>$password]; // Caller renders once; never session-store it.
    }

    public function manage(array $session,string $id,string $role,bool $enabled,int $expectedVersion): void
    {
        $actor=$this->requireOwner($session);if(StaffRole::tryFrom($role)===null)throw new DomainException('Choose an operational role.');
        $this->transaction(function()use($session,$actor,$id,$role,$enabled,$expectedVersion){
            $actor=$this->requireOwner($session);
            $r=$this->row($id,true);if(!$r||$r['realm']!=='staff'||(int)$r['auth_version']!==$expectedVersion)throw new DomainException('This account changed. Reload and try again.');
            $q=$this->db->prepare('UPDATE staff_accounts SET role=?,enabled=?,auth_version=auth_version+1,updated_at=? WHERE account_id=? AND outlet_id=?');
            $q->execute([$role,(int)$enabled,time(),$id,$this->outlet]);$this->audit($actor,$id,'change_staff_access');
        });
    }

    public function reset(array $session,string $id,int $expectedVersion): string
    {
        $actor=$this->requireOwner($session);$password=StaffPasswords::generate();$hash=StaffPasswords::hash($password);
        $this->transaction(function()use($session,$actor,$id,$expectedVersion,$hash){
            $actor=$this->requireOwner($session);
            $r=$this->row($id,true);if(!$r||$r['realm']!=='staff'||(int)$r['auth_version']!==$expectedVersion)throw new DomainException('This account changed. Reload and try again.');
            $q=$this->db->prepare('UPDATE staff_accounts SET password_hash=?,must_change=1,auth_version=auth_version+1,updated_at=? WHERE account_id=? AND outlet_id=?');
            $q->execute([$hash,time(),$id,$this->outlet]);$this->audit($actor,$id,'reset_staff_password');
        });return $password;
    }

    /** DB-backed limits survive browser-session replacement. A failure is committed before denial. */
    public function authenticate(string $username,mixed $candidate,string $peer,bool $reauthentication=false): ?array
    {
        if(strlen($this->rateKey)<32)throw new \RuntimeException('Account authentication configuration is incomplete.');
        $username=strtolower(trim($username));
        $purpose=$reauthentication?'change-':'login-';
        $keys=[hash_hmac('sha256',$purpose.'account:'.$this->outlet.':'.$username,$this->rateKey)=>5,
            hash_hmac('sha256',$purpose.'peer:'.$this->outlet.':'.$peer,$this->rateKey)=>20];ksort($keys);
        return $this->transaction(function()use($keys,$username,$candidate,$purpose,$reauthentication){
            $now=time();$rates=[];
            foreach($keys as $bucket=>$limit){
                $sql=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'
                    ? 'INSERT INTO staff_auth_limits (bucket,failures,window_start,locked_until) VALUES (?,0,?,0) ON DUPLICATE KEY UPDATE bucket=VALUES(bucket)'
                    : 'INSERT OR IGNORE INTO staff_auth_limits (bucket,failures,window_start,locked_until) VALUES (?,0,?,0)';
                $this->db->prepare($sql)->execute([$bucket,$now]);
                $q=$this->db->prepare('SELECT * FROM staff_auth_limits WHERE bucket=?'.$this->lock());$q->execute([$bucket]);$r=$q->fetch(PDO::FETCH_ASSOC);
                if((int)$r['locked_until']>$now){$this->audit('staff-login',null,$reauthentication?'change_password_rate_limited':'login_rate_limited');return null;}
                if((int)$r['window_start']<=$now-900){$r['failures']=0;$r['window_start']=$now;}
                $rates[$bucket]=$r;
            }
            $q=$this->db->prepare("SELECT * FROM staff_accounts WHERE outlet_id=? AND realm='staff' AND username=?".$this->lock());$q->execute([$this->outlet,$username]);$r=$q->fetch(PDO::FETCH_ASSOC);
            // Fixed public dummy hash gives missing accounts a password-verification cost too.
            $dummy='$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi';
            $valid=StaffPasswords::verifies($candidate,$r?$r['password_hash']:$dummy);
            if(!$r||!$valid||(int)$r['enabled']!==1){
                foreach($keys as $bucket=>$limit){$v=$rates[$bucket];$n=(int)$v['failures']+1;$this->db->prepare('UPDATE staff_auth_limits SET failures=?,window_start=?,locked_until=? WHERE bucket=?')->execute([$n,$v['window_start'],$n>=$limit?$now+900:0,$bucket]);}
                $this->audit('staff-login',$r?$r['account_id']:null,$reauthentication?'change_password_rejected':'login_rejected');return null;
            }
            $accountBucket=hash_hmac('sha256',$purpose.'account:'.$this->outlet.':'.$username,$this->rateKey);
            $this->db->prepare('UPDATE staff_auth_limits SET failures=0,window_start=?,locked_until=0 WHERE bucket=?')->execute([$now,$accountBucket]);
            $this->audit('staff:'.$r['account_id'],$r['account_id'],$reauthentication?'change_password_verified':'login_success');return $this->publicRow($r);
        });
    }

    public function identity(array $session): ?array
    {
        $id=$session['_staff_account_id']??null;$version=$session['_staff_account_version']??null;$at=$session['_staff_authenticated_at']??null;
        if(!is_string($id)||!is_int($version)||!is_int($at)||$at>time()||$at<time()-28800)return null;
        $r=$this->row($id);
        if(!$r||$r['realm']!=='staff'||(int)$r['enabled']!==1||(int)$r['auth_version']!==$version||StaffRole::tryFrom($r['role'])===null)return null;
        return $this->publicRow($r);
    }

    public static function bind(array &$session,array $identity): void
    {
        $session['_staff_account_id']=$identity['account_id'];$session['_staff_account_version']=(int)$identity['auth_version'];
        $session['_staff_authenticated_at']=time();$session['staff_role']=$identity['role'];$session['cashier_authenticated']=$identity['role']==='cashier';
    }

    public function changePassword(array $session,mixed $current,mixed $new,mixed $confirmation): array
    {
        if(!is_string($new)||!is_string($confirmation)||!hash_equals($new,$confirmation))throw new DomainException('The new passwords do not match.');
        StaffPasswords::validate($new);
        $identity=$this->identity($session);
        if(!$identity || $this->authenticate($identity['username'],$current,'change:'.$identity['account_id'],true)===null)
            throw new DomainException('Your current password could not be verified. Check it or wait before retrying.');
        $newHash=StaffPasswords::hash($new);
        return $this->transaction(function()use($session,$current,$new,$newHash){
            $id=$session['_staff_account_id']??'';$r=is_string($id)?$this->row($id,true):null;
            if(!$r||!$this->identity($session)||!StaffPasswords::verifies($current,$r['password_hash']))throw new DomainException('Your current password could not be verified.');
            if(StaffPasswords::verifies($new,$r['password_hash']))throw new DomainException('Choose a different new password.');
            $this->db->prepare('UPDATE staff_accounts SET password_hash=?,must_change=0,auth_version=auth_version+1,updated_at=? WHERE account_id=? AND outlet_id=?')->execute([$newHash,time(),$id,$this->outlet]);
            $this->audit('staff:'.$id,$id,'change_own_password');return $this->publicRow($this->row($id));
        });
    }

    /** CLI capability is the operator's authenticated local/SSH process, never a web route. */
    public function recover(string $target,string $operationId,?callable $delivery=null): array
    {
        if(PHP_SAPI!=='cli'||!preg_match('/\A[a-f0-9]{32}\z/',$operationId))throw new DomainException('Authorized CLI recovery is required.');
        if($target!=='owner'&&!preg_match('/\Astaff:[a-f0-9]{32}\z/',$target))throw new DomainException('Select owner or an explicit staff account identifier.');
        $password=StaffPasswords::generate();$hash=StaffPasswords::hash($password);
        return $this->transaction(function()use($target,$operationId,$hash,$password,$delivery){
            if($target==='owner'){
                $q=$this->db->prepare("SELECT * FROM staff_accounts WHERE outlet_id=? AND realm='owner' AND username='owner'".$this->lock());$q->execute([$this->outlet]);$r=$q->fetch(PDO::FETCH_ASSOC);
                if(!$r){$id=bin2hex(random_bytes(16));$this->db->prepare("INSERT INTO staff_accounts (account_id,outlet_id,realm,username,display_name,role,password_hash,enabled,must_change,auth_version,created_at,updated_at) VALUES (?,?,'owner','owner','Owner','owner',?,1,0,1,?,?)")->execute([$id,$this->outlet,$hash,time(),time()]);$r=$this->row($id);}
                elseif((int)$r['enabled']!==1){throw new DomainException('Owner is disabled; password recovery does not change account activation.');}
            } else {$r=$this->row(substr($target,6),true);if(!$r||$r['realm']!=='staff')throw new DomainException('Choose an existing staff account in this outlet.');}
            $this->db->prepare('INSERT INTO staff_auth_recovery_operations (operation_id,outlet_id,target_account_id,occurred_at) VALUES (?,?,?,?)')->execute([$operationId,$this->outlet,$r['account_id'],time()]);
            $this->db->prepare('UPDATE staff_accounts SET password_hash=?,must_change=?,auth_version=auth_version+1,updated_at=? WHERE account_id=? AND outlet_id=?')->execute([$hash,$r['realm']==='staff'?1:0,time(),$r['account_id'],$this->outlet]);
            $this->audit(self::operatorActor(),$r['account_id'],'cli_password_recovery');
            $result=['identity'=>$this->publicRow($this->row($r['account_id'])),'password'=>$password];
            if($delivery!==null)$delivery($result); // A delivery failure rolls back the credential and operation id too.
            return $result;
        });
    }

    public function recordLogout(array $session): void
    {
        $r=$this->identity($session);if($r)$this->audit('staff:'.$r['account_id'],$r['account_id'],'logout');
    }

    /** One-time cutover of existing shared roles; never creates an extra privileged identity. */
    public function provisionLegacy(string $operationId): void
    {
        if(PHP_SAPI!=='cli'||!preg_match('/\A[a-f0-9]{32}\z/',$operationId))throw new DomainException('Explicit CLI provisioning is required.');
        $records=[];
        foreach(StaffRole::cases() as $role){
            $value=Environment::get($role->passwordEnvironmentKey());
            if(!is_string($value)||$value===''||strlen($value)>64||str_contains($value,"\0"))throw new DomainException('A legacy role requires operator review before cutover.');
            $records[]=['role'=>$role,'hash'=>password_hash($value,PASSWORD_DEFAULT)];
        }
        $this->transaction(function()use($records,$operationId){
            foreach($records as $record){$role=$record['role'];$username=match($role){StaffRole::Cashier=>'cashier',StaffRole::GroundFloor=>'ground-floor',StaffRole::FirstFloorKitchen=>'first-floor'};
                $id=bin2hex(random_bytes(16));
                $this->db->prepare("INSERT INTO staff_accounts (account_id,outlet_id,realm,username,display_name,role,password_hash,enabled,must_change,auth_version,created_at,updated_at) VALUES (?,?,'staff',?,?,?,?,1,1,1,?,?)")->execute([$id,$this->outlet,$username,$role->label(),$role->value,$record['hash'],time(),time()]);
                $op=substr(hash('sha256',$operationId.':'.$role->value),0,32);
                $this->db->prepare('INSERT INTO staff_auth_recovery_operations (operation_id,outlet_id,target_account_id,occurred_at) VALUES (?,?,?,?)')->execute([$op,$this->outlet,$id,time()]);
                $this->audit(self::operatorActor(),$id,'provision_legacy_role');
            }
        });
    }
}
