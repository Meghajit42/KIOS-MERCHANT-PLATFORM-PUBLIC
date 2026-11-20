<?php
declare(strict_types=1);
require 'C:/KiosB0/app/bootstrap.php';

use Kios\Infrastructure\{MigrationRunner,StaffPasswords};
use Kios\MerchantOperations\{VirtualPrintWorker};
$result=['passed'=>0,'failed'=>0,'cases'=>[]];
function eidf(int $n):string{return '00000000-0000-4000-8000-'.str_pad((string)$n,12,'0',STR_PAD_LEFT);}
function edb():PDO{return new PDO('mysql:host=127.0.0.1;port=33081;dbname=kios_development','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);}
function ec(bool $ok):void{if(!$ok)throw new RuntimeException('assertion');}
function edenied(callable $f):void{try{$f();}catch(DomainException|InvalidArgumentException|PDOException $e){return;}throw new RuntimeException('expected denial');}
function ecase(string $name,callable $f):void{
    global $result;
    try{$f();$result['passed']++;$result['cases'][$name]='PASS';}
    catch(Throwable $e){$result['failed']++;$result['cases'][$name]=get_class($e).':'.$e->getCode().':'.$e->getMessage();}
}
function edig(PDO $db):array {
    $out=[];
    foreach($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t){
        $rows=$db->query('SELECT * FROM `'.$t.'`')->fetchAll(PDO::FETCH_ASSOC);
        $rows=array_map(fn($r)=>json_encode(array_map(fn($v)=>is_string($v)?['base64'=>base64_encode($v)]:$v,$r),JSON_THROW_ON_ERROR),$rows);
        sort($rows);$out[$t]=['count'=>count($rows),'digest'=>hash('sha256',implode("\n",$rows))];
    }ksort($out);return $out;
}
function ecmd(array $args):void{
    $p=proc_open($args,[0=>['pipe','r'],1=>['file','C:/m01e-command.out','a'],2=>['file','C:/m01e-command.err','a']],$pipes);
    if(!is_resource($p))throw new RuntimeException('command');
    fclose($pipes[0]);if(proc_close($p)!==0)throw new RuntimeException('command exit');
}
final class EClient {
    private array $cookies=[];
    public function request(?array $fields=null,string $method='GET',array $extraHeaders=[]):array {
        $headers=['Connection: close'];
        if($this->cookies)$headers[]='Cookie: '.implode('; ',array_map(fn($k,$v)=>$k.'='.$v,array_keys($this->cookies),$this->cookies));
        $headers=array_merge($headers,$extraHeaders);
        $opts=['method'=>$fields===null?$method:'POST','ignore_errors'=>true,'follow_location'=>0,'timeout'=>15];
        if($fields!==null){$opts['content']=http_build_query($fields);$headers[]='Content-Type: application/x-www-form-urlencoded';}
        $opts['header']=implode("\r\n",$headers);
        $body=file_get_contents('http://127.0.0.1:8089/merchant-dev.php',false,stream_context_create(['http'=>$opts]));
        $status=0;$cookieMetadata=[];
        foreach($http_response_header??[] as $h){
            if(preg_match('/^HTTP\/\S+ (\d+)/',$h,$m))$status=(int)$m[1];
            if(preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i',$h,$m)){
                $this->cookies[$m[1]]=$m[2];
                $cookieMetadata[]=['httpOnly'=>str_contains(strtolower($h),'httponly'),'sameSiteStrict'=>str_contains(strtolower($h),'samesite=strict')];
            }
        }
        return [$status,(string)$body,$cookieMetadata];
    }
    public function csrf():string {
        [$s,$body]=$this->request();
        if(!preg_match('/name="_csrf" value="([a-f0-9]{64})"/',$body,$m))throw new RuntimeException('csrf absent');
        return $m[1];
    }
    public function cookieFingerprint():string{return hash('sha256',json_encode($this->cookies));}
    public function action(string $action,array $fields=[]):array {
        return $this->request(['_csrf'=>$this->csrf(),'action'=>$action]+$fields);
    }
}
$db=edb();
ecase('016 partial DDL detection and exact binary-safe populated recovery',function()use(&$db){
    $before=edig($db);
    ecmd(['C:/Mapped/Tools/mariadb/bin/mysqldump.exe','--no-defaults','--host=127.0.0.1','--port=33081','--user=root',
        '--single-transaction','--hex-blob','--result-file=C:/m01e.sql','--databases','kios_development']);
    $sql=file_get_contents('C:/KiosB0/database/migrations/016_merchant_request_identity.sql');
    $db->exec(preg_split('/;\s*(?:\r?\n|$)/',$sql)[0]);
    ec((int)$db->query("SELECT COUNT(*) FROM schema_migrations WHERE migration='016_merchant_request_identity.sql'")->fetchColumn()===0);
    edenied(fn()=>(new MigrationRunner($db))->apply('C:/KiosB0/database/migrations'));
    ec(edig($db)===$before);
    $db=null;$root=new PDO('mysql:host=127.0.0.1;port=33081','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $root->exec('DROP DATABASE kios_development');
    ecmd(['C:/Mapped/Tools/mariadb/bin/mysql.exe','--no-defaults','--host=127.0.0.1','--port=33081','--user=root','--execute=source C:/m01e.sql']);
    $db=edb();ec(edig($db)===$before);
    ec((new MigrationRunner($db))->apply('C:/KiosB0/database/migrations')===['016_merchant_request_identity.sql']);
    ec((new MigrationRunner($db))->apply('C:/KiosB0/database/migrations')===[]);
    $after=edig($db);foreach($before as $t=>$v)if($t!=='schema_migrations')ec($after[$t]===$v);
    file_put_contents('C:/Mapped/Results/m01e-recovery.json',json_encode(['status'=>'PASS','before'=>$before,'after'=>$after,
        'backupSha256'=>hash_file('sha256','C:/m01e.sql')],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
});
if($result['failed']){file_put_contents('C:/Mapped/Results/m01e-result.json',json_encode($result));exit(1);}
$outlets=[];
foreach(['synthetic-a1','synthetic-b1'] as $code){
    $q=$db->prepare('SELECT outlet_id FROM outlets WHERE outlet_code=?');$q->execute([$code]);$outlets[$code]=(int)$q->fetchColumn();
}
$credentials=[];
foreach([['owner-a',101,$outlets['synthetic-a1'],0],['cashier-a',102,$outlets['synthetic-a1'],0],
    ['kitchen-a',105,$outlets['synthetic-a1'],0],['owner-b',107,$outlets['synthetic-b1'],0],
    ['first-change',104,$outlets['synthetic-a1'],1]] as [$name,$user,$legacy,$mustChange]){
    $password=bin2hex(random_bytes(16));$account=bin2hex(random_bytes(16));
    $db->prepare("INSERT INTO staff_accounts(account_id,outlet_id,realm,username,display_name,role,password_hash,enabled,must_change,auth_version,created_at,updated_at)
        VALUES(?,?,'staff',?,?,'cashier',?,1,?,1,?,?)")
        ->execute([$account,$legacy,$name,'Synthetic '.$name,StaffPasswords::hash($password),$mustChange,time(),time()]);
    $db->prepare('INSERT INTO merchant_auth_links VALUES(?,?,?,1)')->execute([$account,eidf($user),$legacy]);
    $credentials[$name]=['username'=>$name,'password'=>$password,'outlet'=>$legacy,'account'=>$account];
}
function envbase():array {
    $env=[];foreach(['SystemRoot','WINDIR','PATH','PATHEXT','TEMP','TMP','COMSPEC','PHPRC','PHP_INI_SCAN_DIR'] as $n){
        $v=getenv($n);if(is_string($v))$env[$n]=$v;
    }return $env;
}
function startweb(string $enabled,string $rate):mixed {
    $env=envbase()+['APP_ENV'=>'local','KIOS_MERCHANT_DEV_ENABLED'=>$enabled,'KIOS_MERCHANT_AUTH_RATE_KEY'=>$rate];
    $p=proc_open([PHP_BINARY,'-S','127.0.0.1:8089','-t','C:/KiosB0/public','C:/KiosB0/tests/tools/m01e-router.php'],[0=>['pipe','r'],1=>['file','C:/Mapped/Results/m01e-web.stdout.txt','a'],2=>['file','C:/Mapped/Results/m01e-web.stderr.txt','a']],$pipes,'C:/KiosB0',$env);
    fclose($pipes[0]);usleep(400000);return $p;
}
$rate=bin2hex(random_bytes(32));
$server=startweb('0',$rate);
ecase('default-disabled entry point denies requests before authentication',function(){
    ec((new EClient())->request()[0]===404);
});
proc_terminate($server);proc_close($server);
$server=startweb('1',$rate);
$owner=new EClient();$cashier=new EClient();$kitchen=new EClient();$other=new EClient();
$login=function(EClient $client,string $name)use($credentials):void {
    $c=$credentials[$name];
    ec($client->action('login',['username'=>$c['username'],'password'=>$c['password'],'login_outlet'=>(string)$c['outlet']])[0]===303);
};
ecase('unauthenticated action and invalid login CSRF cannot obtain tenant context',function(){
    $c=new EClient();ec($c->action('catalogue',['merchant'=>eidf(1),'outlet'=>eidf(11),'category'=>'sweets'])[0]===401);
    ec($c->request(['action'=>'login','_csrf'=>'bad','login_outlet'=>'1','username'=>'x','password'=>'x'])[0]===400);
});
ecase('native synthetic staff login rotates session and sets protected cookie metadata',function()use($owner,$login){
    [,$body,$cookies]=$owner->request();$before=$owner->cookieFingerprint();
    $login($owner,'owner-a');
    ec($before!==$owner->cookieFingerprint());
    ec(str_contains($owner->request()[1],'Authorized dashboard'));
    ec($cookies[0]['httpOnly'] && $cookies[0]['sameSiteStrict']);
});
$login($cashier,'cashier-a');$login($kitchen,'kitchen-a');$login($other,'owner-b');
$scope=['merchant'=>eidf(1),'outlet'=>eidf(11)];
ecase('valid CSRF with incorrect password cannot authenticate',function()use($credentials){
    $c=new EClient();$a=$credentials['owner-a'];
    ec($c->action('login',['username'=>$a['username'],'password'=>'synthetic-invalid-password','login_outlet'=>$a['outlet']])[0]===401);
    ec(!str_contains($c->request()[1],'Authorized dashboard'));
});
ecase('native session absolute expiration is enforced by the merchant adapter',function()use($db,$credentials,$rate){
    $a=$credentials['owner-a'];
    $native=new \Kios\Infrastructure\StaffAccountService($db,'synthetic-a1',$rate);
    $identity=$native->authenticate($a['username'],$a['password'],'synthetic-expiry-probe');ec($identity!==null);
    $session=[];\Kios\Infrastructure\StaffAccountService::bind($session,$identity);
    $session['_merchant_login_outlet']=(int)$a['outlet'];
    $getter=static function()use(&$session):array{return $session;};
    $adapter=new \Kios\MerchantOperations\StaffSessionIdentity($db,$getter,$rate);
    ec($adapter->currentUserId()===eidf(101));
    $session['_staff_authenticated_at']=time()-28801;
    ec($adapter->currentUserId()===null);
});
ecase('revoked server-owned account link rejects a previously authenticated request',function()use($db,$owner,$credentials,$scope){
    $id=$credentials['owner-a']['account'];
    $db->prepare('UPDATE merchant_auth_links SET active=0 WHERE account_id=?')->execute([$id]);
    try{ec($owner->action('catalogue',$scope+['category'=>'sweets'])[0]===401);}
    finally{$db->prepare('UPDATE merchant_auth_links SET active=1 WHERE account_id=?')->execute([$id]);}
});
ecase('HTTP catalogue is authenticated and rejects valid foreign merchant context',function()use($owner,$scope){
    [$s,$body]=$owner->action('catalogue',$scope+['category'=>'sweets']);
    ec($s===200 && count(json_decode($body,true)['data'])>=1);
    ec($owner->action('catalogue',['merchant'=>eidf(2),'outlet'=>eidf(21),'category'=>'sweets'])[0]===403);
});
ecase('forged user and forwarding headers cannot change authenticated authority',function()use($cashier){
    $fields=['_csrf'=>$cashier->csrf(),'action'=>'catalogue','merchant'=>eidf(2),'outlet'=>eidf(21),'category'=>'sweets','user'=>eidf(107)];
    ec($cashier->request($fields,'POST',['X-Merchant-User: '.eidf(107),'X-Forwarded-For: 127.0.0.1'])[0]===403);
});
ecase('selected server rejects raw legacy routes shared files and financial closing actions',function()use($owner,$scope){
    $options=stream_context_create(['http'=>['ignore_errors'=>true]]);
    foreach(['/cashier.php','/admin/shop-operations.php','/assets/../storage/runtime/print-spool','/../app/bootstrap.php'] as $path){
        file_get_contents('http://127.0.0.1:8089'.$path,false,$options);
        ec(str_contains($http_response_header[0]??'','404'));
    }
    ec($owner->action('end-day',$scope)[0]===403);
});
ecase('protected mutations reject invalid CSRF and unsupported request method',function()use($owner,$scope){
    ec($owner->request(['action'=>'price','_csrf'=>'wrong']+$scope)[0]===400);
    ec($owner->request(null,'PUT')[0]===405);
});
$catalog=json_decode($owner->action('catalogue',$scope+['category'=>'sweets'])[1],true)['data'];
$product=null;
foreach($catalog as $p)if($p['sku']==='SAME-SKU')$product=$p;
$line=['productId'=>$product['productId'],'variantId'=>$product['variants'][0]['variantId'],'quantity'=>1];
$key=bin2hex(random_bytes(32));$orderId=null;$taskId=null;$station=(int)$db->query("SELECT station_id FROM preparation_stations WHERE outlet_id=".$outlets['synthetic-a1']." AND code='SWEETS'")->fetchColumn();
ecase('HTTP pricing validates role ownership and version',function()use($db,$owner,$cashier,$scope,$line){
    $q=$db->prepare('SELECT version FROM product_variants WHERE variant_id=?');$q->execute([$line['variantId']]);$v=(int)$q->fetchColumn();
    $fields=$scope+['product'=>$line['productId'],'variant'=>$line['variantId'],'price'=>1700,'version'=>$v];
    ec($cashier->action('price',$fields)[0]===403);
    ec($owner->action('price',$fields)[0]===200);
    ec($owner->action('price',$fields)[0]===403);
});
ecase('HTTP checkout deduplicates and retrieves correctly owned snapshots',function()use($db,$cashier,$other,$scope,$line,$key,&$orderId){
    $fields=$scope+['lines'=>json_encode([$line]),'intent'=>$key,'type'=>'takeaway'];
    [$s,$body]=$cashier->action('checkout',$fields);ec($s===200);$order=json_decode($body,true)['data'];
    ec($order['totalPaise']===1700 && $order['status']==='awaiting_payment');
    $again=json_decode($cashier->action('checkout',$fields)[1],true)['data'];ec($again['id']===$order['id']);
    $q=$db->prepare('SELECT order_id FROM orders WHERE public_id=?');$q->execute([$order['id']]);$orderId=(int)$q->fetchColumn();
    ec($cashier->action('order',$scope+['order'=>$orderId])[0]===200);
    $foreign=json_decode($other->action('order',['merchant'=>eidf(2),'outlet'=>eidf(21),'order'=>$orderId])[1],true);
    ec($foreign['data']===null);
});
ecase('HTTP cash routing kitchen KOT READY and collection complete using authorized sessions',function()use($db,$owner,$cashier,$kitchen,$scope,$station,&$orderId,&$taskId){
    ec($cashier->action('pay',$scope+['order'=>$orderId])[0]===200);
    $q=$db->prepare('SELECT preparation_task_id FROM preparation_tasks WHERE order_id=?');$q->execute([$orderId]);$taskId=(int)$q->fetchColumn();
    ec($kitchen->action('queue',$scope+['station'=>$station])[0]===200);
    ec($kitchen->action('kot',$scope+['task'=>$taskId])[0]===200);
    ec($cashier->action('advance',$scope+['task'=>$taskId,'expected'=>'queued'])[0]===403);
    ec($kitchen->action('advance',$scope+['task'=>$taskId,'expected'=>'queued'])[0]===200);
    ec($kitchen->action('advance',$scope+['task'=>$taskId,'expected'=>'preparing'])[0]===200);
    ec($cashier->action('collect',$scope+['order'=>$orderId])[0]===200);
    ec($owner->action('prints',$scope)[0]===200);
    ec($kitchen->action('prints',$scope)[0]===403);
});
ecase('authentication version revocation invalidates an existing HTTP session',function()use($db,$cashier,$credentials,$scope){
    $db->prepare('UPDATE staff_accounts SET auth_version=auth_version+1 WHERE account_id=?')->execute([$credentials['cashier-a']['account']]);
    ec($cashier->action('catalogue',$scope+['category'=>'sweets'])[0]===401);
});
ecase('mandatory first-password change remains enforced then enables authorized access',function()use($login,$scope,$credentials){
    $c=new EClient();$login($c,'first-change');
    ec(str_contains($c->request()[1],'Change password required'));
    ec($c->action('catalogue',$scope+['category'=>'sweets'])[0]===401);
    $newPassword=bin2hex(random_bytes(16));
    ec($c->action('change-password',['current_password'=>$credentials['first-change']['password'],
        'new_password'=>$newPassword,'confirm_password'=>$newPassword])[0]===303);
    ec($c->action('catalogue',$scope+['category'=>'sweets'])[0]===200);
});
ecase('logout invalidates authenticated dashboard',function()use($other){
    ec($other->action('logout')[0]===303);
    ec(!str_contains($other->request()[1],'Authorized dashboard'));
});
$workerId=eidf(900);$workerActor=eidf(901);$credential=bin2hex(random_bytes(32));
$db->prepare('INSERT INTO merchant_staff VALUES(?,?,1)')->execute([$workerActor,'Synthetic virtual service']);
$db->prepare("INSERT INTO merchant_worker_grants VALUES(?,?,?,?,?,?,'virtual_print',1,?)")
    ->execute([$workerId,$workerActor,eidf(1),eidf(11),$outlets['synthetic-a1'],hash('sha256',$credential),time()+600]);
$job=(int)$db->query("SELECT print_job_id FROM print_jobs WHERE outlet_id=".$outlets['synthetic-a1']." AND status='printed' LIMIT 1")->fetchColumn();
ecase('worker has one scope no staff permissions and a canonical audited actor',function()use($db,$workerId,$workerActor,$credential,$job){
    $db->exec("UPDATE print_jobs SET status='failed' WHERE print_job_id=$job");
    $w=new VirtualPrintWorker($db,$workerId,$credential);
    ec($w->deliver(eidf(1),eidf(11),$job)->value==='printed');
    ec((int)$db->query("SELECT COUNT(*) FROM merchant_outlet_memberships WHERE user_id=".$db->quote($workerActor))->fetchColumn()===0);
    ec($db->query("SELECT actor FROM print_job_attempts WHERE print_job_id=$job ORDER BY attempt_number DESC LIMIT 1")->fetchColumn()===$workerActor);
});
ecase('isolated CLI worker uses explicit credential and one scope',function()use($workerId,$credential,$job){
    $env=envbase()+['APP_ENV'=>'local','KIOS_MERCHANT_DEV_ENABLED'=>'1','KIOS_WORKER_ID'=>$workerId,'KIOS_WORKER_CREDENTIAL'=>$credential];
    $p=proc_open([PHP_BINARY,'C:/KiosB0/bin/merchant-virtual-worker.php',eidf(1),eidf(11),(string)$job],
        [0=>['pipe','r'],1=>['file','C:/m01e-cli.out','a'],2=>['file','C:/m01e-cli.err','a']],$pipes,'C:/KiosB0',$env);
    fclose($pipes[0]);ec(proc_close($p)===0);
});
ecase('auth and worker foreign-key constraints deny mismatched mappings',function()use($db,$outlets){
    edenied(function()use($db,$outlets){
        $db->prepare('INSERT INTO merchant_auth_links VALUES(?,?,?,1)')->execute([str_repeat('a',32),eidf(101),$outlets['synthetic-b1']]);
    });
    edenied(function()use($db,$outlets){
        $db->prepare("INSERT INTO merchant_worker_grants VALUES(?,?,?,?,?,?,'virtual_print',1,?)")
            ->execute([eidf(902),eidf(901),eidf(2),eidf(11),$outlets['synthetic-a1'],str_repeat('a',64),time()+60]);
    });
});
ecase('worker denies a foreign immutable job and a nonvirtual snapshot',function()use($db,$workerId,$credential,$job,$outlets){
    $w=new VirtualPrintWorker($db,$workerId,$credential);
    $foreign=(int)$db->query('SELECT print_job_id FROM print_jobs WHERE outlet_id='.$outlets['synthetic-b1'].' LIMIT 1')->fetchColumn();
    ec($foreign>0);edenied(fn()=>$w->deliver(eidf(1),eidf(11),$foreign));
    $db->exec("UPDATE print_jobs SET adapter_type_snapshot='windows_spooler' WHERE print_job_id=$job");
    try{edenied(fn()=>$w->deliver(eidf(1),eidf(11),$job));}
    finally{$db->exec("UPDATE print_jobs SET adapter_type_snapshot='virtual' WHERE print_job_id=$job");}
});
ecase('invalid worker credentials foreign scope and expired grants deny delivery',function()use($db,$workerId,$credential,$job){
    edenied(fn()=>(new VirtualPrintWorker($db,$workerId,str_repeat('a',64)))->deliver(eidf(1),eidf(11),$job));
    edenied(fn()=>(new VirtualPrintWorker($db,$workerId,$credential))->deliver(eidf(2),eidf(21),$job));
    $db->prepare('UPDATE merchant_worker_grants SET expires_at=0 WHERE service_id=?')->execute([$workerId]);
    edenied(fn()=>(new VirtualPrintWorker($db,$workerId,$credential))->deliver(eidf(1),eidf(11),$job));
});
file_put_contents('C:/M01EBrowserCredentials.json',json_encode(['owner'=>$credentials['owner-a'],'kitchen'=>$credentials['kitchen-a'],
    'other'=>$credentials['owner-b'],'merchant'=>eidf(1),'outlet'=>eidf(11),'foreignMerchant'=>eidf(2),'foreignOutlet'=>eidf(21),
    'station'=>$station,'line'=>$line],JSON_THROW_ON_ERROR));
file_put_contents('C:/Mapped/Results/m01e-result.json',json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
// Keep this isolated test server alive for the following browser probe. The guest is disposed afterward.
$browserCode=1;
try {
    $p=proc_open(['C:/Mapped/Tools/node/node.exe','C:/KiosB0/tests/tools/m01e-browser.js'],
        [0=>['pipe','r'],1=>['file','C:/Mapped/Results/m01e-browser.stdout.txt','a'],
        2=>['file','C:/Mapped/Results/m01e-browser.stderr.txt','a']],$pipes,'C:/KiosB0',envbase());
    fclose($pipes[0]);$browserCode=proc_close($p);
} finally {
    proc_terminate($server);proc_close($server);
}
exit($result['failed']===0 && $browserCode===0?0:1);