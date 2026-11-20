<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/bootstrap.php';
use Kios\Infrastructure\{StaffAccountService,StaffPasswords};

function authFixture(): array {
    $db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA foreign_keys=ON; CREATE TABLE outlets (outlet_id INTEGER PRIMARY KEY,outlet_code TEXT NOT NULL,active INTEGER NOT NULL); INSERT INTO outlets VALUES (1,\'demo-outlet\',1),(2,\'other-outlet\',1);');
    $sql=file_get_contents(dirname(__DIR__).'/database/migrations/011_staff_accounts.sql');
    $sql=preg_replace('/ CHARACTER SET ascii COLLATE ascii_bin/','',$sql);
    $sql=preg_replace('/UNIQUE KEY [a-z_]+/','UNIQUE',$sql);
    $sql=preg_replace('/^\s*KEY [^\n]+\n/m','',$sql);
    $sql=str_replace(' ENGINE=InnoDB','',$sql);$db->exec($sql);
    $hash=StaffPasswords::hash('synthetic-owner-fixture-credential');
    $db->prepare("INSERT INTO staff_accounts VALUES (?,1,'owner','owner','Owner','owner',?,1,0,1,?,?)")->execute([str_repeat('f',32),$hash,time(),time()]);
    if(!StaffPasswords::verifies('synthetic-owner-fixture-credential',$hash))throw new RuntimeException('fixture owner authentication failed');
    $service=new StaffAccountService($db,'demo-outlet',str_repeat('x',32));
    $owner=['menu_admin_authenticated'=>true,'menu_admin_actor'=>'menu-admin','_owner_account_tag'=>$service->ownerTag()];
    return [$db,$service,$owner];
}
function authAssert(bool $ok,string $label): void {if(!$ok)throw new RuntimeException($label);}
function authDenied(callable $f): void {try{$f();}catch(DomainException){return;}throw new RuntimeException('unauthorized or invalid operation accepted');}
function authSession(array $identity): array {$s=[];StaffAccountService::bind($s,$identity);return $s;}

return [
 'staff accounts use generated hashes and a closed role set'=>static function():void{
  [$db,$s,$o]=authFixture();$a=$s->create($o,'ground-floor','Synthetic operator','ground_floor');
  $hash=$db->query("SELECT password_hash FROM staff_accounts WHERE realm='staff'")->fetchColumn();
  authAssert(StaffPasswords::verifies($a['password'],$hash)&&$hash!==$a['password'],'credential was not hashed');
  authAssert(strlen($a['password'])===32,'generated entropy encoding changed');
  authDenied(fn()=>$s->create($o,'bad-role','Operator','owner'));
  authDenied(fn()=>StaffPasswords::hash('short'));
  authDenied(fn()=>StaffPasswords::hash(str_repeat('z',65)));
  authDenied(fn()=>StaffPasswords::hash("long-synthetic-password\0"));
 },
 'owner capability is required for every staff management read and mutation'=>static function():void{
  [$db,$s,$o]=authFixture();$a=$s->create($o,'ground-floor','Operator','ground_floor');
  foreach([[],['staff_role'=>'cashier'],['menu_admin_authenticated'=>true,'menu_admin_actor'=>'menu-admin','_owner_account_tag'=>'forged']] as $bad){
   authDenied(fn()=>$s->create($bad,'attacker','No','ground_floor'));authDenied(fn()=>$s->reset($bad,$a['accountId'],1));
   authDenied(fn()=>$s->manage($bad,$a['accountId'],'cashier',true,1));authDenied(fn()=>$s->listAccounts($bad));authDenied(fn()=>$s->auditHistory($bad));
  }
  $other=new StaffAccountService($db,'other-outlet',str_repeat('x',32));authAssert(!$other->ownerValid($o),'owner session crossed outlet scope');
 },
 'correct account login is accepted and disabled or wrong credentials rejected'=>static function():void{
  [$db,$s,$o]=authFixture();$a=$s->create($o,'ground-floor','Operator','ground_floor');
  authAssert($s->authenticate('ground-floor','not-the-password','peer-one')===null,'wrong password accepted');
  $identity=$s->authenticate('GROUND-FLOOR',$a['password'],'peer-one');authAssert($identity!==null&&$identity['role']==='ground_floor','valid role login failed');
  authAssert(!isset($identity['password_hash']),'hash escaped identity projection');
  $s->manage($o,$a['accountId'],'ground_floor',false,1);
  authAssert($s->identity(authSession($identity))===null,'disabled session survived');
  authAssert($s->authenticate('ground-floor',$a['password'],'peer-one')===null,'disabled account accepted');
 },
 'account password reset revokes all sessions and old password without changing roles'=>static function():void{
  [$db,$s,$o]=authFixture();$a=$s->create($o,'ground-floor','Operator','ground_floor');
  $old=$s->authenticate('ground-floor',$a['password'],'peer-one');$session=authSession($old);
  $new=$s->reset($o,$a['accountId'],1);
  authAssert($s->identity($session)===null,'old session survived reset');
  authAssert($s->authenticate('ground-floor',$a['password'],'peer-one')===null,'old password survived reset');
  $identity=$s->authenticate('ground-floor',$new,'peer-one');authAssert($identity!==null&&(int)$identity['must_change']===1&&$identity['role']==='ground_floor','reset lost permissions or change requirement');
  authDenied(fn()=>$s->reset($o,$a['accountId'],1));
 },
 'staff change requires current password and matching valid replacement and revokes other sessions'=>static function():void{
  [$db,$s,$o]=authFixture();$a=$s->create($o,'ground-floor','Operator','ground_floor');$session=authSession($s->authenticate('ground-floor',$a['password'],'peer-one'));
  authDenied(fn()=>$s->changePassword($session,'wrong','new-synthetic-password','new-synthetic-password'));
  authDenied(fn()=>$s->changePassword($session,$a['password'],'new-synthetic-password','mismatch'));
  authDenied(fn()=>$s->changePassword($session,$a['password'],$a['password'],$a['password']));
  $identity=$s->changePassword($session,$a['password'],'new-synthetic-password','new-synthetic-password');
  authAssert((int)$identity['must_change']===0&&$s->identity($session)===null,'change did not clear temporary state or revoke old session');
  authAssert($s->identity(authSession($identity))!==null,'fresh changed-password session rejected');
  authAssert($s->authenticate('ground-floor',$a['password'],'peer-one')===null,'old password accepted after change');
 },
 'rate limiting is persistent across browser sessions and includes unknown accounts'=>static function():void{
  [$db,$s,$o]=authFixture();$a=$s->create($o,'ground-floor','Operator','ground_floor');
  for($i=0;$i<5;$i++)authAssert($s->authenticate('ground-floor','wrong','one-peer')===null,'wrong credential accepted');
  $fresh=new StaffAccountService($db,'demo-outlet',str_repeat('x',32));authAssert($fresh->authenticate('ground-floor',$a['password'],'different-peer')===null,'account lock bypassed through peer/session replacement');
  for($i=0;$i<20;$i++)$s->authenticate('unknown-'.$i,'wrong','shared-peer');
  authAssert($s->authenticate('ground-floor',$a['password'],'shared-peer')===null,'peer lock bypassed');
  $db->exec('UPDATE staff_auth_limits SET locked_until=0,window_start=0');authAssert($s->authenticate('ground-floor',$a['password'],'one-peer')!==null,'expired lock failed to recover');
 },
 'account access changes and absolute expiry invalidate cached authorization'=>static function():void{
  [$db,$s,$o]=authFixture();$a=$s->create($o,'floor-operator','Operator','ground_floor');$identity=$s->authenticate('floor-operator',$a['password'],'peer');$session=authSession($identity);
  $expired=$session;$expired['_staff_authenticated_at']=time()-28801;authAssert($s->identity($expired)===null,'expired session accepted');
  $forged=$session;$forged['staff_role']='cashier';authAssert($s->identity($forged)['role']==='ground_floor','cached role escalated privileges');
  $s->manage($o,$a['accountId'],'first_floor_kitchen',true,1);authAssert($s->identity($session)===null,'access change did not revoke session');
 },
 'CLI recovery is explicit one-time audited and invalidates owner and staff sessions'=>static function():void{
  [$db,$s,$o]=authFixture();$a=$s->create($o,'floor-operator','Operator','ground_floor');$session=authSession($s->authenticate('floor-operator',$a['password'],'peer'));
  $id=bin2hex(random_bytes(16));$r=$s->recover('staff:'.$a['accountId'],$id);authAssert($r['identity']['role']==='ground_floor'&&$s->identity($session)===null,'staff recovery did not preserve role/revoke');
  $before=$db->query('SELECT COUNT(*) FROM staff_auth_audit')->fetchColumn();
  try{$s->recover('staff:'.$a['accountId'],$id);throw new RuntimeException('recovery id replay accepted');}catch(PDOException){}
  authAssert($db->query('SELECT COUNT(*) FROM staff_auth_audit')->fetchColumn()===$before,'replay audit transaction not rolled back');
  $owner=$s->recover('owner',bin2hex(random_bytes(16)));authAssert(!$s->ownerValid($o)&&StaffPasswords::verifies($owner['password'],$s->ownerHash()),'owner recovery did not replace hash and invalidate stamp');
  authDenied(fn()=>$s->recover('unspecified',bin2hex(random_bytes(16))));
 },
 'security audit contains identities and actions but no credential material'=>static function():void{
  [$db,$s,$o]=authFixture();$a=$s->create($o,'floor-operator','Operator','ground_floor');$identity=$s->authenticate('floor-operator',$a['password'],'peer');$s->recordLogout(authSession($identity));$reset=$s->reset($o,$a['accountId'],1);
  $events=$s->auditHistory($o);$data=json_encode($events,JSON_THROW_ON_ERROR);
  authAssert(count($events)===4,'audit events missing');
  foreach([$a['password'],$reset,(string)$db->query("SELECT password_hash FROM staff_accounts WHERE realm='staff'")->fetchColumn()] as $secret)authAssert(!str_contains($data,$secret),'credential appeared in audit');
  authAssert(str_contains($data,'owner:menu-admin')&&str_contains($data,$a['accountId']),'audit attribution missing');
 },
 'recovery delivery failure rolls back credential operation id and audit'=>static function():void{
  [$db,$s,$o]=authFixture();$a=$s->create($o,'floor-operator','Operator','ground_floor');$operation=bin2hex(random_bytes(16));
  try{$s->recover('staff:'.$a['accountId'],$operation,static function(){throw new RuntimeException('synthetic delivery failure');});throw new LogicException('delivery failure ignored');}catch(RuntimeException $e){authAssert($e->getMessage()==='synthetic delivery failure','unexpected recovery exception');}
  authAssert($s->authenticate('floor-operator',$a['password'],'peer')!==null,'failed delivery replaced original password');
  authAssert((int)$db->query('SELECT COUNT(*) FROM staff_auth_recovery_operations')->fetchColumn()===0,'failed delivery consumed operation id');
  $s->recover('staff:'.$a['accountId'],$operation);
 },
 'recovery encrypted delivery is readable only with recipient private key'=>static function():void{
  if(!function_exists('sodium_crypto_box_seal'))throw new RuntimeException('recovery tests require sodium');
  [$db,$s,$o]=authFixture();$keys=sodium_crypto_box_keypair();$sealed=null;
  $r=$s->recover('owner',bin2hex(random_bytes(16)),static function(array $r)use($keys,&$sealed){$sealed=sodium_crypto_box_seal(json_encode($r,JSON_THROW_ON_ERROR),sodium_crypto_box_publickey($keys));});
  $opened=sodium_crypto_box_seal_open($sealed,$keys);authAssert(is_string($opened),'recipient could not open recovery envelope');
  $data=json_decode($opened,true,512,JSON_THROW_ON_ERROR);authAssert(StaffPasswords::verifies($data['password'],$s->ownerHash()),'delivered credential did not verify');
  authAssert(!str_contains($sealed,$r['password']),'recovery envelope contained plaintext');
  authAssert(sodium_crypto_box_seal_open($sealed,sodium_crypto_box_keypair())===false,'wrong recipient opened recovery envelope');
 },
 'one-time legacy cutover hashes existing roles without changing credentials or creating owners'=>static function():void{
  [$db,$s,$o]=authFixture();$keys=['KIOS_CASHIER_PASSWORD','KIOS_GROUND_FLOOR_PASSWORD','KIOS_FIRST_FLOOR_KITCHEN_PASSWORD'];$before=[];
  try{foreach($keys as $key){$before[$key]=getenv($key);putenv($key.'=synthetic-legacy-fixture');}
   $op=bin2hex(random_bytes(16));$s->provisionLegacy($op);
   foreach(['cashier','ground-floor','first-floor'] as $name){$identity=$s->authenticate($name,'synthetic-legacy-fixture','peer');authAssert($identity!==null&&(int)$identity['must_change']===1,'legacy credential or mandatory-change state lost');}
   authAssert((int)$db->query("SELECT COUNT(*) FROM staff_accounts WHERE realm='owner'")->fetchColumn()===1,'cutover created an extra owner');
   try{$s->provisionLegacy($op);throw new RuntimeException('duplicate cutover accepted');}catch(PDOException){}
   authAssert((int)$db->query("SELECT COUNT(*) FROM staff_accounts WHERE realm='staff'")->fetchColumn()===3,'cutover replay created duplicate accounts');
  }finally{foreach($before as $key=>$value){if($value===false)putenv($key);else putenv($key.'='.$value);}}
 },
 'password-change verification has persistent throttling separate from ordinary sign-in'=>static function():void{
  [$db,$s,$o]=authFixture();$a=$s->create($o,'floor-operator','Operator','ground_floor');$session=authSession($s->authenticate('floor-operator',$a['password'],'peer'));
  for($i=0;$i<5;$i++)authDenied(fn()=>$s->changePassword($session,'wrong','new-synthetic-password','new-synthetic-password'));
  authDenied(fn()=>$s->changePassword($session,$a['password'],'new-synthetic-password','new-synthetic-password'));
  authAssert($s->authenticate('floor-operator',$a['password'],'peer')!==null,'change throttle unexpectedly disabled valid sign-in');
 },
 'owner login stamp is bound to verified hash and owner throttling survives session replacement'=>static function():void{
  [$db,$s,$o]=authFixture();
  $tag=$s->authenticateOwner('synthetic-owner-fixture-credential','peer');authAssert($tag===$s->ownerTag(),'valid owner stamp failed');
  for($i=0;$i<5;$i++)authAssert($s->authenticateOwner('wrong','peer')===null,'wrong owner credential accepted');
  $fresh=new StaffAccountService($db,'demo-outlet',str_repeat('x',32));authAssert($fresh->authenticateOwner('synthetic-owner-fixture-credential','other-peer')===null,'owner lock bypassed by session/peer replacement');
  $db->exec('UPDATE staff_auth_limits SET window_start=0,locked_until=0');
  authAssert($s->authenticateOwner('synthetic-owner-fixture-credential','peer')!==null,'owner cooldown expiration failed');
  $s->recover('owner',bin2hex(random_bytes(16)));authAssert(!$s->ownerValid($o)&&$tag!==$s->ownerTag(),'recovered owner accepted an old verified stamp');
 },
];
