<?php
declare(strict_types=1);
namespace Kios\CustomerIdentity;
use PDO;
use Kios\Infrastructure\Environment;
use Kios\MerchantFoundation\{AuthenticatedUserProvider,Identity};
use Kios\MerchantOperations\MerchantProvisioner;

/** Internal synthetic account domain. No HTTP routes, credentials, customer login or token issuance. */
final class CustomerAccounts
{
 public function __construct(private PDO $db){}
 private function enabled():void{if(Environment::get('APP_ENV')!=='demo'||Environment::get('KIOS_CUSTOMER_IDENTITY_ENABLED','0')!=='1')throw new \DomainException('Customer foundation disabled.');if($this->db->inTransaction())throw new \LogicException('Account service owns transaction.');}
 private function rows(string $sql,array $v=[]):array{$q=$this->db->prepare($sql);$q->execute($v);return$q->fetchAll(PDO::FETCH_ASSOC);}
 private function lock(string $id):array{Identity::validate($id);$r=$this->rows('SELECT * FROM customer_accounts WHERE customer_id=? FOR UPDATE',[$id]);if(!$r)throw new \DomainException('Account unavailable.');return$r[0];}
 private function event(string $id,string $domain,string $actor,string $action,int $version):void{$this->rows('INSERT INTO customer_security_events VALUES(?,?,?,?,?,?,?)',[bin2hex(random_bytes(16)),$id,$domain,$actor,$action,$version,time()]);}
 private function name(string $name):string{if(!preg_match('/^Synthetic [A-Za-z0-9 ._-]{1,80}$/D',$name))throw new \InvalidArgumentException('Use a synthetic display label.');return$name;}
 private function publicRecord(array $r):array{return array_intersect_key($r,array_flip(['customer_id','account_handle','display_name','status','contact_state','version']));}
 public function create(AuthenticatedUserProvider $platform,string $handle,string $name):array{
  $this->enabled();$handle=strtolower(trim($handle));if(!preg_match('/^synthetic-[a-z0-9][a-z0-9_-]{2,53}$/D',$handle))throw new \InvalidArgumentException('Synthetic account handle required.');$name=$this->name($name);
  return(new MerchantProvisioner($this->db,$platform))->administer(function($actor)use($handle,$name){$id=Identity::generate();$this->rows("INSERT INTO customer_accounts VALUES(?,?,?,'PENDING','UNVERIFIED',1,?,?)",[$id,$handle,$name,time(),time()]);$this->event($id,'PLATFORM',$actor,'CREATE',1);return$this->publicRecord($this->lock($id));});
 }
 public function transition(AuthenticatedUserProvider $platform,string $id,int $expected,string $next):array{
  $this->enabled();return(new MerchantProvisioner($this->db,$platform))->administer(function($actor)use($id,$expected,$next){$r=$this->lock($id);$allowed=['PENDING'=>['ACTIVE','DEACTIVATED'],'ACTIVE'=>['SUSPENDED','DEACTIVATED'],'SUSPENDED'=>['ACTIVE','DEACTIVATED'],'DEACTIVATED'=>[]];if((int)$r['version']!==$expected||!in_array($next,$allowed[$r['status']],true))throw new \DomainException('Invalid state or version conflict.');$this->rows('UPDATE customer_accounts SET status=?,version=version+1,updated_epoch=? WHERE customer_id=?',[$next,time(),$id]);$this->event($id,'PLATFORM',$actor,'STATE_'.$next,$expected+1);return$this->publicRecord($this->lock($id));});
 }
 private function own(CustomerIdentityProvider $auth,string $id,callable $work):mixed{
  $this->enabled();Identity::validate($id);$actor=$auth->currentCustomerId();if($actor!==$id)throw new \DomainException('Customer ownership required.');$this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$this->db->beginTransaction();try{$r=$this->lock($id);if($auth->currentCustomerId()!==$actor||$r['status']==='DEACTIVATED')throw new \DomainException('Customer account unavailable.');$result=$work($r);$this->db->commit();return$result;}catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw$e;}
 }
 public function profile(CustomerIdentityProvider $auth,string $id):array{return$this->own($auth,$id,fn($r)=>$this->publicRecord($r));}
 public function updateProfile(CustomerIdentityProvider $auth,string $id,int $expected,string $name):array{
  $name=$this->name($name);return$this->own($auth,$id,function($r)use($id,$expected,$name){if($r['status']!=='ACTIVE'||(int)$r['version']!==$expected)throw new \DomainException('Active current account required.');$this->rows('UPDATE customer_accounts SET display_name=?,version=version+1,updated_epoch=? WHERE customer_id=?',[$name,time(),$id]);$this->event($id,'CUSTOMER',$id,'PROFILE',$expected+1);return$this->publicRecord($this->lock($id));});
 }
 public function consent(CustomerIdentityProvider $auth,string $id,int $expected,string $purpose,string $policy,bool $granted):array{
  if(!in_array($purpose,['terms','privacy','marketing'],true)||!preg_match('/^[a-zA-Z0-9._-]{1,32}$/D',$policy))throw new \InvalidArgumentException('Explicit versioned consent required.');return$this->own($auth,$id,function($r)use($id,$expected,$purpose,$policy,$granted){if($r['status']!=='ACTIVE'||(int)$r['version']!==$expected)throw new \DomainException('Active current account required.');$this->rows('UPDATE customer_accounts SET version=version+1,updated_epoch=? WHERE customer_id=?',[time(),$id]);$this->rows('INSERT INTO customer_consents VALUES(?,?,?,?,?,?,?)',[bin2hex(random_bytes(16)),$id,$purpose,$policy,(int)$granted,$expected+1,time()]);$this->event($id,'CUSTOMER',$id,'CONSENT',$expected+1);return$this->publicRecord($this->lock($id));});
 }
}
