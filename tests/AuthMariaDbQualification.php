<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('KIOS_AUTH_DISPOSABLE_DB')!=='AUTH_P0_DISPOSABLE_GUEST')exit(2);
require dirname(__DIR__).'/app/bootstrap.php';
use Kios\Infrastructure\{Database,MigrationRunner,DemoSeeder,OrderRepository,StaffAccountService,StaffPasswords};
use Kios\Domain\{OrderType,PaymentMethod};
$guest=getenv('KIOS_AUTH_TEST_GUEST');
if(!is_string($guest)||!is_file($guest.'/disposable.marker')||!str_starts_with(realpath(dirname(__DIR__)),realpath($guest).DIRECTORY_SEPARATOR))exit(3);
try {
 $db=Database::connect();
 $runtime=$db->query('SELECT VERSION() AS version,@@datadir AS data_dir,@@port AS port')->fetch(PDO::FETCH_ASSOC);
 if(!str_starts_with($runtime['version'],'11.8.9-')||(int)$runtime['port']!==33081
    ||realpath($runtime['data_dir'])!==realpath($guest.'/db'))throw new RuntimeException('disposable database runtime guard failed');
 $runner=new MigrationRunner($db);
 if(($argv[1]??'')==='restore'){
  $restore=new PDO('mysql:host=127.0.0.1;port=33081;dbname=kios_auth_restore;charset=utf8mb4',getenv('KIOS_DB_USER'),getenv('KIOS_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  $same=true;foreach(['orders','order_items','payment_confirmations','preparation_tasks','print_jobs','staff_accounts','staff_auth_audit','staff_auth_limits','schema_migrations'] as $table){$a=$db->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);$b=$restore->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);$same=$same&&$a===$b;}
  if(!$same)throw new RuntimeException('restore comparison failed');echo "PASS disposable encrypted-credential schema/data dump restoration\n";exit;
 }
 $base=$guest.'/base-migrations';mkdir($base);foreach(glob(dirname(__DIR__).'/database/migrations/*.sql') as $file)if(basename($file)!=='011_staff_accounts.sql')copy($file,$base.'/'.basename($file));
 if(count($runner->apply($base))!==10)throw new RuntimeException('base ledger not ten');
 (new DemoSeeder($db))->seed();require dirname(__DIR__).'/bin/seed-virtual-printers.php';
 // Synthetic paid order proves additive migration and auth changes do not mutate business data.
 $db->exec("UPDATE products SET routing_status='approved'");
 $row=$db->query("SELECT p.sku,v.code FROM products p JOIN product_variants v ON v.product_id=p.product_id WHERE p.outlet_id=(SELECT outlet_id FROM outlets WHERE outlet_code='demo-outlet') AND v.unit='piece' AND p.active=1 AND p.available=1 AND v.active=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
 if(!$row)throw new RuntimeException('synthetic order fixture unavailable');
 $repo=new OrderRepository($db);$order=$repo->createFromCart([$row['sku']=>[$row['code']=>1]],OrderType::Takeaway);
 $repo->confirmPaymentByInternalToken($order->token,PaymentMethod::Cash);
 $tables=['orders','order_items','order_status_history','payment_confirmations','preparation_tasks','preparation_task_items','print_jobs','print_job_attempts','products','categories','preparation_stations'];
 $snapshot=static function()use($db,$tables){$out=[];foreach($tables as $table)$out[$table]=$db->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);return serialize($out);};$before=$snapshot();
 $new=$runner->apply(dirname(__DIR__).'/database/migrations');
 if($new!==['011_staff_accounts.sql']||$runner->apply(dirname(__DIR__).'/database/migrations')!==[])throw new RuntimeException('append-only migration ledger failed');
 echo "PASS ten-to-eleven migration and rerun idempotency\n";
 $service=new StaffAccountService($db,'demo-outlet',str_repeat('q',32));
 $owner=['menu_admin_authenticated'=>true,'menu_admin_actor'=>'menu-admin','_owner_account_tag'=>$service->ownerTag()];
 if(!$service->ownerValid($owner))throw new RuntimeException('owner stamp failed');
 $a=$service->create($owner,'mariadb-ground-floor','Synthetic operator','ground_floor');
 if($service->authenticate('mariadb-ground-floor','wrong','peer')!==null)throw new RuntimeException('wrong credential accepted');
 $identity=$service->authenticate('mariadb-ground-floor',$a['password'],'peer');if(!$identity)throw new RuntimeException('valid account denied');
 $session=[];StaffAccountService::bind($session,$identity);
 $changed=$service->changePassword($session,$a['password'],'synthetic-new-database-password','synthetic-new-database-password');
 if($service->identity($session)!==null||(int)$changed['must_change']!==0)throw new RuntimeException('change did not revoke and clear temporary state');
 $session=[];StaffAccountService::bind($session,$changed);$new=$service->reset($owner,$a['accountId'],(int)$changed['auth_version']);
 if($service->identity($session)!==null||$service->authenticate('mariadb-ground-floor','synthetic-new-database-password','peer')!==null)throw new RuntimeException('reset invalidation failed');
 $identity=$service->authenticate('mariadb-ground-floor',$new,'peer');if(!$identity)throw new RuntimeException('reset credential denied');
 $service->manage($owner,$a['accountId'],'ground_floor',false,(int)$identity['auth_version']);
 if($service->authenticate('mariadb-ground-floor',$new,'peer')!==null)throw new RuntimeException('disabled account accepted');
 echo "PASS MariaDB create, valid/invalid login, change, reset, session revocation and disabled-account denial\n";
 $recover=$service->recover('owner',bin2hex(random_bytes(16)));
 if($service->ownerValid($owner)||!StaffPasswords::verifies($recover['password'],$service->ownerHash()))throw new RuntimeException('owner recovery failed');
 echo "PASS MariaDB owner recovery invalidates old owner session\n";
 $owner['_owner_account_tag']=$service->authenticateOwner($recover['password'],'database-owner-peer');
 $service->provisionLegacy(bin2hex(random_bytes(16)));
 $concurrent=$service->create($owner,'mariadb-concurrent','Concurrent synthetic operator','ground_floor');
 $workers=[];
 for($i=0;$i<2;$i++){
  $p=proc_open([PHP_BINARY,__DIR__.'/AuthRateWorker.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));
  if(!is_resource($p))throw new RuntimeException('concurrent worker did not start');fclose($pipes[0]);$workers[]=[$p,$pipes];
 }
 file_put_contents($guest.'/rate-workers.go','go');
 foreach($workers as [$p,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($p)!==0||trim($out)!=='PASS'||$err!=='')throw new RuntimeException('concurrent worker failed');}
 if($service->authenticate('mariadb-concurrent',$concurrent['password'],'other-peer')!==null)throw new RuntimeException('concurrent account lock not preserved');
 echo "PASS concurrent MariaDB failure accounting and persistent lockout\n";
 if($before!==$snapshot())throw new RuntimeException('business snapshot changed during account operations');
 echo "PASS paid order/payment/task/print/catalogue snapshots unchanged\n";
 if((int)$db->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn()!==11)throw new RuntimeException('ledger count invalid');
 echo "AUTH_DATABASE_QUALIFICATION_PASS\n";
}catch(Throwable){fwrite(STDERR,"AUTH_DATABASE_QUALIFICATION_FAILED_NO_SECRET_DETAILS\n");exit(1);}
