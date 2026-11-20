<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/app/bootstrap.php';
require __DIR__.'/migration-sequence.php';
use Kios\Infrastructure\{Database,StaffAccountService};
use Kios\MerchantOperations\{StaffSessionIdentity,PlatformAdministration,PlatformAccess,Entitlements,OwnerOnboarding,RestaurantSetup,Seating,StaffPos,IntegratedSetup,Inventory,BrandProfile,BillDrafts,UsageBilling,OperationalAnalytics,PrivateApi};
$db=Database::connect();$meta=json_decode(file_get_contents($argv[1]),true);$creds=json_decode(file_get_contents($argv[2]),true);$a=$meta['merchants']['a'];$b=$meta['merchants']['b'];$c=$meta['merchants']['c'];$r=['passed'=>0,'failed'=>0,'cases'=>[]];
function ok(bool$x,string$message='Assertion failed'):void{if(!$x)throw new RuntimeException($message);}function no(callable$f):void{try{$f();}catch(DomainException|InvalidArgumentException|PDOException){return;}throw new RuntimeException('Expected denial');}function k():string{return bin2hex(random_bytes(16));}function test(string$n,callable$f):void{global$r;try{$f();$r['passed']++;$r['cases'][$n]='PASS';}catch(Throwable$x){$r['failed']++;$r['cases'][$n]=get_class($x).':'.$x->getMessage().' at '.basename($x->getFile()).':'.$x->getLine();}}
function auth(array$m,string$user,string$pw):StaffSessionIdentity{global$db;$q=$db->prepare('SELECT outlet_code FROM outlets WHERE outlet_id=?');$q->execute([$m['loginOutlet']]);$n=(new StaffAccountService($db,$q->fetchColumn(),getenv('KIOS_MERCHANT_AUTH_RATE_KEY')))->authenticate($user,$pw,'m06c-synthetic');ok($n!==null);$s=[];StaffAccountService::bind($s,$n);$s['_merchant_login_outlet']=$m['loginOutlet'];return new StaffSessionIdentity($db,static fn()=>$s,getenv('KIOS_MERCHANT_AUTH_RATE_KEY'));}
$platform=auth($meta['platform'],$creds['platform_username'],$creds['platform_password']);$oa=auth($a,$a['username'],$creds['owner_a_password']);$ob=auth($b,$b['username'],$creds['owner_b_password']);$oc=auth($c,$c['username'],$creds['owner_c_password']);putenv('KIOS_PLATFORM_ADMIN_ENABLED=1');$p=new PlatformAdministration($db,$platform);
function ver(array$m):string{global$p;return(string)$p->detail($m['merchant'])['merchant']['admin_version'];}
function act(array$m,string$op,array$data,?string$key=null):array{global$p;return$p->act($m['merchant'],ver($m),$key??k(),$op,$data,'Synthetic M06C qualified change','yes');}
function provisionData(string$suffix):array{return['business_name'=>'Synthetic Platform '.$suffix,'business_type'=>'restaurant','admin_contact'=>'platform-'.$suffix.'@example.test','outlet_name'=>'Synthetic Outlet '.$suffix,'owner_name'=>'Synthetic owner '.$suffix,'owner_username'=>'m06c-owner-'.$suffix,'opening_time'=>'08:00','closing_time'=>'22:00'];}


// Keep the deterministic rate-window fixture before wall time used by the subsequent browser suite.
putenv('KIOS_PRIVATE_API_ENABLED=1');$clock=time()+120;$api=new PrivateApi($db,$oa,fn()=>$GLOBALS['clock']);$q=['merchant'=>$a['merchant'],'outlet'=>$a['outlet']];
function request($route='merchant',$query=null,$method='GET',$principal=null,$header='',$idem=''):array{global$api,$q,$db;return($principal?new PrivateApi($db,$principal,fn()=>$GLOBALS['clock']):$api)->request($method,'/api/v1/'.$route,$query??$q,'127.0.0.1',$header,$idem);}
function status($r,$n):void{ok($r['status']===$n,'Expected '.$n.' got '.$r['status'].' '.json_encode($r['body']['error']));ok($r['body']['api_version']==='v1'&&preg_match('/^[a-f0-9]{32}$/D',$r['body']['request_id']));if($n!==200)ok($r['body']['data']===null);}
$before=[];foreach(['products','product_variants','orders','payment_confirmations','merchant_usage_ledger','merchant_bill_drafts','merchant_brand_profiles']as$t)$before[$t]=$db->query('SELECT * FROM '.$t)->fetchAll(PDO::FETCH_ASSOC);
if(($argv[4]??'')==='worker'){$clock=(int)$argv[5];echo request()['status'];exit(0);}

$profiles=[['merchant'=>$a['merchant'],'outlet'=>$a['outlet'],'visible'=>true,'categories'=>['restaurant'],'cuisines'=>['synthetic-cuisine']]];
function profiles(array $v):void{putenv('KIOS_PRIVATE_DISCOVERY_SCOPES='.json_encode($v));}
profiles($profiles);

putenv('KIOS_PRIVATE_DISCOVERY_ENABLED=1');$clock+=1000;$captured=[];
foreach(['restaurants','customer-catalogue']as$route){foreach([[],['page'=>'2','per_page'=>'1'],['category'=>'not-present'],['cuisine'=>'not-present'],['q'=>'NoSuchSynthetic'],['sort'=>'name-desc']]as$extra){$response=request($route,$q+$extra);status($response,200);$captured[]=['route'=>$route,'status'=>200,'body'=>$response['body']];}}
foreach([['merchant'=>$a['merchant']],['outlet'=>$a['outlet']],$q+['per_page'=>'101'],$q+['category'=>'bad value']]as$invalid){$response=request('restaurants',$invalid);status($response,400);$captured[]=['route'=>'restaurants','status'=>400,'body'=>$response['body']];}
file_put_contents($argv[3],json_encode($captured,JSON_PRETTY_PRINT));echo 'Captured 16 actual API responses';
