<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/app/bootstrap.php';
use Kios\Infrastructure\{Database,MigrationRunner,StaffPasswords,StaffAccountService,Environment};
use Kios\MerchantFoundation\{AuthenticatedUserProvider,Identity};
use Kios\MerchantOperations\{MerchantProvisioner,OwnerOnboarding,RestaurantSetup,BrandProfile};
try {
 $creds=json_decode(file_get_contents($argv[1]),true,32,JSON_THROW_ON_ERROR);
 $db=Database::connect();$name=$db->query('SELECT DATABASE()')->fetchColumn();
 if(!in_array($name,['kios_private_demo','kios_development'],true))throw new RuntimeException('Dedicated demo database required');
 $db->exec("SET time_zone='+00:00'");
 if($db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()")->fetchColumn()!=0)throw new RuntimeException('Fresh empty demo database required');
 $applied=(new MigrationRunner($db))->apply(dirname(__DIR__).'/database/migrations');
 if(count($applied)!==38)throw new RuntimeException('Expected all 38 migrations');
 $id=Identity::generate();$account=bin2hex(random_bytes(16));$now=time();
 $db->exec("INSERT INTO outlets(outlet_code,name,active) VALUES('private-platform','Synthetic platform access',1)");
 $platformOutlet=(int)$db->lastInsertId();
 $db->prepare('INSERT INTO merchant_staff VALUES(?,?,1)')->execute([$id,'Demo platform operator']);
 $db->prepare('INSERT INTO merchant_platform_memberships(user_id) VALUES(?)')->execute([$id]);
 $db->prepare("INSERT INTO staff_accounts(account_id,outlet_id,realm,username,display_name,role,password_hash,enabled,must_change,auth_version,created_at,updated_at) VALUES(?,?,'staff',?,'Demo platform operator','cashier',?,1,0,1,?,?)")->execute([$account,$platformOutlet,$creds['platform_username'],StaffPasswords::hash($creds['platform_password']),$now,$now]);
 $db->prepare('INSERT INTO merchant_auth_links VALUES(?,?,?,1)')->execute([$account,$id,$platformOutlet]);
 $auth=static fn(string $user)=>new class($user) implements AuthenticatedUserProvider {public function __construct(private string $user){}public function currentUserId():?string{return $this->user;}};
 $provisioner=new MerchantProvisioner($db,$auth($id));
 $meta=['platform'=>['username'=>$creds['platform_username'],'loginOutlet'=>$platformOutlet],'merchants'=>[],'migrations'=>count($applied)];
 foreach(['a','b'] as $label) {
  $a=$provisioner->provision(bin2hex(random_bytes(16)),['business_name'=>'Demo Restaurant '.strtoupper($label),'business_type'=>'restaurant','admin_contact'=>'owner-'.$label.'@example.test','outlet_name'=>'Demo Outlet '.strtoupper($label),'owner_name'=>'Demo owner '.strtoupper($label),'owner_username'=>$creds['owner_'.$label.'_username'],'opening_time'=>'08:00','closing_time'=>'22:00'])['receipt'];
  $pw=$creds['owner_'.$label.'_password'];
  $a=$provisioner->activate($a['merchant_id'],1,bin2hex(random_bytes(16)),$pw,$pw)['receipt'];
  $owner=$auth($a['owner_user_id']);
  $setup=(new OwnerOnboarding($db,$owner))->load($a['merchant_id']);
  $restaurant=new RestaurantSetup($db,$owner);
  $act=static function(string $op,array $data)use($restaurant,$a):array{$s=$restaurant->load($a['merchant_id'],$a['merchant_outlet_id']);return $restaurant->act($a['merchant_id'],$a['merchant_outlet_id'],(int)$s['version'],bin2hex(random_bytes(16)),$op,$data)['result'];};
  $category=$act('category',['id'=>0,'name'=>$label==='a'?'Bowls':'Cafe plates','sort'=>0])['categoryId'];
  $station=$act('station',['id'=>0,'code'=>$label==='a'?'SWEETS':'SOUTH_INDIAN','name'=>$label==='a'?'Demo preparation counter':'Demo hot kitchen','department'=>$label==='a'?'GROUND_FLOOR':'FIRST_FLOOR_KITCHEN','active'=>1])['stationId'];
  $product=$act('product',['id'=>0,'name'=>$label==='a'?'Demo vegetable bowl':'Demo breakfast plate','description'=>'Synthetic menu example; not a real merchant price.','sku'=>'DEMO-'.strtoupper($label),'category'=>$category,'station'=>$station,'available'=>1,'variants'=>[['id'=>0,'label'=>'Regular','unit'=>'portion','quantity'=>1,'price'=>$label==='a'?'120.00':'90.00'],['id'=>0,'label'=>'Small','unit'=>'portion','quantity'=>1,'price'=>$label==='a'?'70.00':'55.00']]])['productId'];
  $staff=$act('staff-create',['username'=>'demo-kitchen-'.$label,'name'=>'Demo kitchen '.$label,'role'=>'kitchen']);
  $brand=new BrandProfile($db,$owner);$s=$brand->load($a['merchant_id'],$a['merchant_outlet_id']);$c=BrandProfile::defaults();$c['name']='Demo Restaurant '.strtoupper($label);if($label==='b'){$c['primary']='#1e40af';$c['accent']='#1e3a8a';$c['template']='counter';}
  $brand->act($a['merchant_id'],$a['merchant_outlet_id'],(int)$s['version'],bin2hex(random_bytes(16)),'save',$c);
  $brand->layout($a['merchant_id'],$a['merchant_outlet_id']);
  $design=$brand->designs($a['merchant_id'],$a['merchant_outlet_id']);$brand->publish($a['merchant_id'],$a['merchant_outlet_id'],(int)$design['revision'],bin2hex(random_bytes(16)));
  $q=$db->prepare('SELECT outlet_code FROM outlets WHERE outlet_id=?');$q->execute([$a['legacy_outlet_id']]);
  $native=(new StaffAccountService($db,$q->fetchColumn(),Environment::get('KIOS_MERCHANT_AUTH_RATE_KEY')))->authenticate($a['owner_username'],$pw,'synthetic-cloud-seed');
  if($native===null)throw new RuntimeException('Seed owner authentication failed');
  $meta['merchants'][$label]=['merchant'=>$a['merchant_id'],'outlet'=>$a['merchant_outlet_id'],'loginOutlet'=>(int)$a['legacy_outlet_id'],'username'=>$a['owner_username'],'product'=>$product,'category'=>$category,'station'=>$station,'staff'=>$staff];
 }
 // Verify no operational or financial activity was seeded.
 foreach(['orders','payment_confirmations','thermal_print_jobs'] as $table){
  $exists=$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=".$db->quote($table))->fetchColumn();
  if($exists && (int)$db->query("SELECT COUNT(*) FROM $table")->fetchColumn()!==0)throw new RuntimeException('Unexpected operational records');
 }
 if((int)$db->query('SELECT COUNT(*) FROM shop_operations WHERE manual_enabled<>0 OR day_ended<>0')->fetchColumn()!==0)throw new RuntimeException('Ordering must stay closed');
 file_put_contents($argv[2],json_encode($meta,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
 echo json_encode(['status'=>'PASS','migrations'=>count($applied),'merchants'=>2,'syntheticOnly'=>true,'orderingClosed'=>true,'ordersCreated'=>0,'passwordsPrinted'=>false]).PHP_EOL;
}catch(Throwable $e){echo json_encode(['status'=>'FAIL','type'=>get_class($e),'code'=>(string)$e->getCode(),'reason'=>$e instanceof DomainException||$e instanceof InvalidArgumentException?$e->getMessage():'Private seed failed; see protected diagnostic']).PHP_EOL;file_put_contents($argv[2].'.error',get_class($e).':'.$e->getMessage());exit(1);}
