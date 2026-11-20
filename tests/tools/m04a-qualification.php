<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/app/bootstrap.php';
require __DIR__.'/migration-sequence.php';
use Kios\MerchantOperations\{CloudDemo,StaffSessionIdentity,OwnerOnboarding,RestaurantSetup,BrandProfile};
use Kios\Infrastructure\{Database,StaffAccountService};
$r=['passed'=>0,'failed'=>0,'cases'=>[]];
function t(string $n,callable $f):void{global $r;try{$f();$r['passed']++;$r['cases'][$n]='PASS';}catch(Throwable $e){$r['failed']++;$r['cases'][$n]=get_class($e).':'.$e->getMessage();}}
function ok(bool $v):void{if(!$v)throw new RuntimeException('Assertion failed');}
function deny(callable $f):void{try{$f();}catch(DomainException|InvalidArgumentException){return;}throw new RuntimeException('Expected authorization denial');}
t('Cloud exposure fails closed before protected gate',function(){putenv('APP_ENV=demo');putenv('KIOS_MERCHANT_CLOUD_ENABLED=1');ok(!CloudDemo::allowed(['HTTP_HOST'=>'kios-merchant-demo.joviaaninternational.com','HTTPS'=>'on']));});
define('KIOS_PRIVATE_GATE_VERIFIED',true);
t('Cloud guard requires explicit demo enablement exact host and HTTPS',function(){
 $s=['HTTP_HOST'=>'kios-merchant-demo.joviaaninternational.com','HTTPS'=>'on'];ok(CloudDemo::allowed($s));
 foreach([['HTTP_HOST'=>'joviaaninternational.com','HTTPS'=>'on'],['HTTP_HOST'=>'kios-merchant-demo.joviaaninternational.com.evil.test','HTTPS'=>'on'],['HTTP_HOST'=>$s['HTTP_HOST'],'HTTPS'=>'off']] as $bad)ok(!CloudDemo::allowed($bad));
 putenv('KIOS_MERCHANT_CLOUD_ENABLED=0');ok(!CloudDemo::allowed($s));putenv('KIOS_MERCHANT_CLOUD_ENABLED=1');putenv('APP_ENV=local');ok(!CloudDemo::allowed($s));
});
t('Cloud action allowlist rejects payments checkout prints and closing',function(){foreach(['checkout','pay','deliver','advance','collect','financial-end-day','open','register'] as $a)ok(!CloudDemo::actionAllowed($a));foreach(['login','logout','brand-save','brand-upload','brand-confirm','brand-restore','layout-save','design-publish','design-restore','restaurant-staff-access'] as $a)ok(CloudDemo::actionAllowed($a));});
$meta=json_decode(file_get_contents($argv[1]),true);$creds=json_decode(file_get_contents($argv[2]),true);$db=Database::connect();$providers=[];
t('Twenty-three migrations applied and no ordering payment or print records',function()use($db){ok(qualifiedMigrationSequence($db));ok((int)$db->query('SELECT COUNT(*) FROM orders')->fetchColumn()===0);ok((int)$db->query('SELECT COUNT(*) FROM payment_confirmations')->fetchColumn()===0);ok((int)$db->query('SELECT COUNT(*) FROM shop_operations WHERE manual_enabled<>0')->fetchColumn()===0);});
foreach(['a','b'] as $w)t('Real staff authentication and server-owned tenant link '.$w,function()use($w,$db,$meta,$creds,&$providers){
 $m=$meta['merchants'][$w];$q=$db->prepare('SELECT outlet_code FROM outlets WHERE outlet_id=?');$q->execute([$m['loginOutlet']]);$svc=new StaffAccountService($db,$q->fetchColumn(),getenv('KIOS_MERCHANT_AUTH_RATE_KEY'));
 ok($svc->authenticate($m['username'],'incorrect-synthetic-password','test-wrong')===null);
 $native=$svc->authenticate($m['username'],$creds['owner_'.$w.'_password'],'test-correct');ok($native!==null);$session=[];StaffAccountService::bind($session,$native);$session['_merchant_login_outlet']=$m['loginOutlet'];
 $providers[$w]=new StaffSessionIdentity($db,static fn()=>$session,getenv('KIOS_MERCHANT_AUTH_RATE_KEY'));ok($providers[$w]->currentUserId()!==null);
});
if(count($providers)===2){
 $a=$meta['merchants']['a'];$b=$meta['merchants']['b'];
 t('Native authenticated merchant dashboard renders all integrated tools',function()use($db,$providers,$a){$h=CloudDemo::dashboard($db,$providers['a'],['merchant_id'=>$a['merchant'],'merchant_outlet_id'=>$a['outlet']],str_repeat('c',64));ok(str_contains($h,'Demo Restaurant A'));ok(substr_count($h,'class="dashboard-tool"')===9);ok(!str_contains($h,'merchant-dev.php'));});
 t('Cross-merchant setup catalogue branding and design access denied',function()use($db,$providers,$b){deny(fn()=>(new OwnerOnboarding($db,$providers['a']))->load($b['merchant']));deny(fn()=>(new RestaurantSetup($db,$providers['a']))->load($b['merchant'],$b['outlet']));deny(fn()=>(new BrandProfile($db,$providers['a']))->load($b['merchant'],$b['outlet']));deny(fn()=>(new BrandProfile($db,$providers['a']))->designs($b['merchant'],$b['outlet']));});
 t('Draft publication history restoration keep merchants independent',function()use($db,$providers,$a,$b){
  $brand=new BrandProfile($db,$providers['a']);$before=$brand->designs($a['merchant'],$a['outlet']);$old=$before['active'];$foreign=(new BrandProfile($db,$providers['b']))->designs($b['merchant'],$b['outlet']);
  $layout=$brand->layout($a['merchant'],$a['outlet']);$c=$layout['config'];$c['cards']='compact';$brand->layout($a['merchant'],$a['outlet'],$layout['version'],bin2hex(random_bytes(16)),$c);
  $draft=$brand->designs($a['merchant'],$a['outlet']);ok($draft['active']===$old);
  $brand->publish($a['merchant'],$a['outlet'],$draft['revision'],bin2hex(random_bytes(16)));$after=$brand->designs($a['merchant'],$a['outlet']);ok(count($after['history'])===count($before['history'])+1);
  $brand->publish($a['merchant'],$a['outlet'],$after['revision'],bin2hex(random_bytes(16)),$old);ok($brand->designs($a['merchant'],$a['outlet'])['active']===$old);
  ok((new BrandProfile($db,$providers['b']))->designs($b['merchant'],$b['outlet'])===$foreign);
 });
 t('Cloud presentation maps only browser navigation without exposing dev endpoint',function(){ok(str_contains(CloudDemo::presentation('<body><a href="/merchant-dev.php?layout=1">Editor</a></body>'),'/merchant.php?layout=1'));});
}
file_put_contents($argv[3],json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));echo json_encode(['passed'=>$r['passed'],'failed'=>$r['failed']]).PHP_EOL;exit($r['failed']?1:0);
