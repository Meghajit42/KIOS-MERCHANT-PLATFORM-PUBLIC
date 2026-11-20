<?php
declare(strict_types=1);
namespace Kios\MerchantOperations;
use PDO;
use Kios\Infrastructure\Environment;
use Kios\MerchantFoundation\{AuthenticatedUserProvider,TenantContext,PdoIdentityStore,Permission,Identity};
/** Read-only private adapter. No token issuer, order creation or financial transport. */
final class PrivateApi
{
 public function __construct(private PDO $db,private AuthenticatedUserProvider $auth,private ?\Closure $clock=null){}
 public static function enabled():bool{return Environment::get('APP_ENV')==='demo'&&Environment::get('KIOS_PRIVATE_API_ENABLED','0')==='1'&&PlatformAccess::enabled()&&Environment::get('KIOS_PUBLIC_ORDERING_ENABLED','0')==='0'&&Environment::get('KIOS_PUBLIC_REGISTRATION_ENABLED','0')==='0'&&Environment::get('KIOS_PAYMENTS_ENABLED','0')==='0'&&Environment::get('KIOS_PHYSICAL_PRINTING_ENABLED','0')==='0';}
 private function rows(string $s,array $a=[]):array{$q=$this->db->prepare($s);$q->execute($a);return$q->fetchAll(PDO::FETCH_ASSOC);}
 private function now():int{return$this->clock?($this->clock)():time();}
 public function request(string $method,string $path,array $q,string $peer,string $authorization='',string $idempotency='',int $bodyLength=0):array
 {
  $id=bin2hex(random_bytes(16));$route=str_starts_with($path,'/api/v1/')?substr($path,8):'unknown';$known=['merchant','outlet','catalogue','availability','configuration','operational-status','restaurants','customer-catalogue'];$code=200;$error=null;$data=null;$page=null;$actor=null;$scope=null;
  if(!self::enabled())return$this->response(404,'NOT_FOUND',null,$id,null);
  $key=Environment::get('KIOS_MERCHANT_AUTH_RATE_KEY','');if(strlen($key)<32)return$this->response(503,'UNAVAILABLE',null,$id,null);
  try{
   $actor=$this->auth->currentUserId();
   if(!$actor||($authorization!==''&&!str_starts_with($authorization,'Basic '))){$code=401;$error='AUTHENTICATION_REQUIRED';}
   elseif(!in_array($method,['GET','HEAD'],true)){$code=405;$error='METHOD_NOT_ALLOWED';}
   elseif(!in_array($route,$known,true)||(in_array($route,['restaurants','customer-catalogue'],true)&&Environment::get('KIOS_PRIVATE_DISCOVERY_ENABLED','0')!=='1')){$code=404;$error='NOT_FOUND';}
   else{
    if(array_diff(array_keys($q),array_merge(['merchant','outlet','page','per_page','q'],in_array($route,['restaurants','customer-catalogue'],true)?['category','cuisine','sort']:[]))||$idempotency!==''||$bodyLength!==0)throw new \InvalidArgumentException();
    foreach($q as$v)if(!is_string($v))throw new \InvalidArgumentException();
    $m=$q['merchant']??'';$o=$q['outlet']??'';Identity::validate($m);Identity::validate($o);
    foreach(['page'=>1,'per_page'=>20]as$k=>$default){$v=$q[$k]??(string)$default;if(!preg_match('/^[1-9][0-9]{0,4}$/D',$v))throw new \InvalidArgumentException();$q[$k]=(int)$v;}if($q['page']>10000||$q['per_page']>100)throw new \InvalidArgumentException();
    $search=$q['q']??'';if(!mb_check_encoding($search,'UTF-8')||mb_strlen($search)>80||preg_match('/[\x00-\x1f\x7f<>]/u',$search))throw new \InvalidArgumentException();
    $scope=TenantContext::resolve($this->auth,new PdoIdentityStore($this->db),$m,$o);
    if(!$scope->allows(Permission::ReadOrders)||in_array($route,['configuration','operational-status'],true)&&!$scope->allows(Permission::ManageOutlet))throw new \DomainException();
    (new Entitlements($this->db,$this->auth))->requireFeature($m,$o,'menu','read');
    if($route==='operational-status')(new Entitlements($this->db,$this->auth))->requireFeature($m,$o,'pos','drain-read');
    $map=$this->rows('SELECT legacy_outlet_id FROM merchant_legacy_outlets WHERE merchant_id=? AND merchant_outlet_id=?',[$m,$o]);if(!$map)throw new \DomainException();$legacy=(int)$map[0]['legacy_outlet_id'];
    if(in_array($route,['restaurants','customer-catalogue'],true))$data=(new PrivateDiscovery($this->db,$this->auth))->read($route,$m,$o,$legacy,$q);
    elseif($route==='merchant')$data=$this->rows('SELECT merchant_id,name FROM merchant_organizations WHERE merchant_id=?',[$m]);
    elseif($route==='outlet')$data=$this->rows('SELECT outlet_id,merchant_id,name FROM merchant_outlets WHERE merchant_id=? AND outlet_id=?',[$m,$o]);
    elseif($route==='configuration'){$c=IntegratedSetup::configuration($this->db,$m,$o);$data=[['merchant_id'=>$m,'outlet_id'=>$o,'selected_modules'=>$c?json_decode($c['modules_json'],true):null,'reviewed'=>$c?(bool)$c['reviewed']:null,'scope'=>'configuration summary; no credentials or private profile']];}
    elseif($route==='operational-status'){$counts=$this->rows('SELECT status,COUNT(*) count FROM merchant_service_sessions WHERE merchant_id=? AND outlet_id=? GROUP BY status',[$m,$o]);$data=[['merchant_id'=>$m,'outlet_id'=>$o,'services'=>$counts,'manualShopEnabled'=>(bool)$this->rows('SELECT manual_enabled FROM shop_operations WHERE outlet_id=?',[$legacy])[0]['manual_enabled'],'publicOrdering'=>'CLOSED','payments'=>'DISABLED','physicalPrinting'=>'DISABLED']];}
    else{
     $data=[];$native=new TenantRestaurant($this->db,$this->auth);foreach($this->rows('SELECT slug FROM categories WHERE outlet_id=? AND active=1 ORDER BY category_id',[$legacy])as$c)foreach($native->catalogue($m,$o,$c['slug'])as$product)$data[]=$product;
     if($route==='availability'){$eligible=array_column($data,'productId');$data=array_map(fn($p)=>['productId'=>(int)$p['product_id'],'name'=>$p['name'],'available'=>(bool)$p['available'],'orderableWhenAuthorized'=>in_array((int)$p['product_id'],$eligible,true)],$this->rows('SELECT product_id,name,available FROM products WHERE outlet_id=? AND active=1 ORDER BY product_id',[$legacy]));}
    }
    $data=array_values(array_filter($data,fn($v)=>$search===''||mb_stripos((string)($v['name']??'').($route==='restaurants'?' '.($v['merchantName']??''):''),$search)!==false));$total=count($data);$data=array_slice($data,($q['page']-1)*$q['per_page'],$q['per_page']);$page=['page'=>$q['page'],'per_page'=>$q['per_page'],'total'=>$total];
   }
  }catch(\InvalidArgumentException|\ValueError|\TypeError){$code=400;$error='INVALID_REQUEST';$data=null;$page=null;$scope=null;}
  catch(\DomainException){$code=403;$error='ACCESS_DENIED';$data=null;$page=null;$scope=null;}
  catch(\Throwable){return$this->response(503,'UNAVAILABLE',null,$id,null);}
  try{
   if($this->db->inTransaction())throw new \LogicException();$this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$this->db->beginTransaction();
   $bucket=hash_hmac('sha256',$actor?'principal:'.$actor:'anonymous:'.$peer,$key);$now=$this->now();$this->rows('INSERT INTO merchant_api_limits VALUES(?,?,0) ON DUPLICATE KEY UPDATE bucket=VALUES(bucket)',[$bucket,$now]);$limit=$this->rows('SELECT * FROM merchant_api_limits WHERE bucket=? FOR UPDATE',[$bucket])[0];$window=(int)$limit['window_epoch'];$n=(int)$limit['requests'];if($window<=$now-60){$window=$now;$n=0;}++$n;$this->rows('UPDATE merchant_api_limits SET window_epoch=?,requests=? WHERE bucket=?',[$window,min($n,1000000),$bucket]);
   if($n>($actor?60:20)){$code=429;$error='RATE_LIMITED';$data=null;$page=null;}
   elseif($code===200&&$scope){
    // Revalidate native identity and current authority under locks before returning the previously read model.
    if($this->auth->currentUserId()!==$actor)throw new \DomainException();$s=$this->rows('SELECT active FROM merchant_staff WHERE user_id=? FOR UPDATE',[$actor]);if(!$s||(int)$s[0]['active']!==1)throw new \DomainException();
    $perm=in_array($route,['configuration','operational-status'],true)?'manage_outlet':'read_orders';$r=$this->rows('SELECT role_code FROM merchant_memberships WHERE merchant_id=? AND user_id=? AND active=1 FOR UPDATE',[$scope->merchantId,$actor]);$r=array_merge($r,$this->rows('SELECT role_code FROM merchant_outlet_memberships WHERE merchant_id=? AND outlet_id=? AND user_id=? AND active=1 FOR UPDATE',[$scope->merchantId,$scope->outletId,$actor]));$roles=array_column($r,'role_code');if(!$roles)throw new \DomainException();$allowed=false;foreach($roles as$role)if(\Kios\MerchantFoundation\Role::from($role)->allows(Permission::from($perm)))$allowed=true;if(!$allowed)throw new \DomainException();
    (new Entitlements($this->db,$this->auth))->requireFeature($scope->merchantId,$scope->outletId,'menu','read');if($route==='operational-status')(new Entitlements($this->db,$this->auth))->requireFeature($scope->merchantId,$scope->outletId,'pos','drain-read');
   }
   $this->rows('INSERT INTO merchant_api_events VALUES(?,?,?,?,?,?)',[$id,$actor,in_array($route,$known,true)?$route:'unknown',$code,hash_hmac('sha256',json_encode([$q['merchant']??null,$q['outlet']??null]),$key),$now]);$this->db->commit();
  }catch(\Throwable){if($this->db->inTransaction())$this->db->rollBack();return$this->response(503,'UNAVAILABLE',null,$id,null);}
  return$this->response($code,$error,$data,$id,$page);
 }
 private function response(int $status,?string $error,?array $data,string $id,?array $page):array{return['status'=>$status,'body'=>['api_version'=>'v1','request_id'=>$id,'data'=>$error?null:$data,'error'=>$error?['code'=>$error]:null,'pagination'=>$page],'headers'=>['X-Request-Id'=>$id,'Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff','Vary'=>'Cookie, Authorization']+($status===405?['Allow'=>'GET, HEAD']:[])+($status===429?['Retry-After'=>'60']:[])];}
}
