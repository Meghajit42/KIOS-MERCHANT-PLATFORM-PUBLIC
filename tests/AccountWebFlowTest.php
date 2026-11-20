<?php
declare(strict_types=1);

final class KiosAccountHttpClient
{
    private array $cookies=[];
    public function __construct(private string $base){}
    public function request(string $path,?array $fields=null): array {
        $headers=['Connection: close'];
        if($this->cookies)$headers[]='Cookie: '.implode('; ',array_map(static fn($k,$v)=>$k.'='.$v,array_keys($this->cookies),$this->cookies));
        $options=['method'=>$fields===null?'GET':'POST','header'=>implode("\r\n",$headers),'ignore_errors'=>true,'follow_location'=>0,'timeout'=>10];
        if($fields!==null){$options['content']=http_build_query($fields);$options['header'].="\r\nContent-Type: application/x-www-form-urlencoded";}
        $body=@file_get_contents($this->base.$path,false,stream_context_create(['http'=>$options]));
        if(!is_string($body))throw new RuntimeException('fixture HTTP request did not complete');
        $status=0;$location=null;
        foreach($http_response_header??[] as $h){
            if(preg_match('~^HTTP/\S+ (\d+)~',$h,$m))$status=(int)$m[1];
            if(preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i',$h,$m))$this->cookies[$m[1]]=$m[2];
            if(str_starts_with(strtolower($h),'location:'))$location=trim(substr($h,9));
        }
        return [$status,$body,$location];
    }
    public static function field(string $body,string $name): string {
        if(!preg_match('/name="'.preg_quote($name,'/').'" value="([^"]*)"/',$body,$m))throw new RuntimeException('fixture security form control absent');
        return html_entity_decode($m[1],ENT_QUOTES,'UTF-8');
    }
}

return ['account-enabled owner and staff HTTP flow enforces password change CSRF revocation and logout'=>static function(string $root):void{
    $dir=sys_get_temp_dir().DIRECTORY_SEPARATOR.'kios-account-http-'.bin2hex(random_bytes(10));mkdir($dir,0700);
    $process=null;$db=null;
    $php=[PHP_BINARY,'-n','-d','extension_dir='.ini_get('extension_dir')];
    foreach(['pdo_sqlite','pdo_mysql','gd','fileinfo','mbstring'] as $ext)$php=array_merge($php,['-d','extension=php_'.$ext.'.dll']);
    // The repository's qualified runtime is Windows PHP; POSIX runs use its normal extension names.
    if(PHP_OS_FAMILY!=='Windows')$php=[PHP_BINARY];
    $check=static function(bool $ok,string $label):void{if(!$ok)throw new RuntimeException('account HTTP fixture: '.$label);};
    try{
        $setup=proc_open(array_merge($php,[$root.'/tests/AccountFixtureSetup.php',$dir]),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root);
        fclose($pipes[0]);$output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($setup);
        $check($code===0&&trim($output)==='PASS'&&$errors==='','database setup');
        $socket=stream_socket_server('tcp://127.0.0.1:0');$address=stream_socket_get_name($socket,false);fclose($socket);
        $env=[];foreach(['SystemRoot','WINDIR','PATH','PATHEXT','TEMP','TMP','COMSPEC'] as $key)if(is_string(getenv($key)))$env[$key]=getenv($key);
        $env['KIOS_ACCOUNT_HTTP_FIXTURE']=$dir;
        $process=proc_open(array_merge($php,['-d','auto_prepend_file='.$root.'/tests/AccountServerBootstrap.php','-S',$address,'-t',$root.'/public']),[0=>['pipe','r'],1=>['file',$dir.'/server.stdout','a'],2=>['file',$dir.'/server.stderr','a']],$pipes,$root,$env);
        fclose($pipes[0]);$client=new KiosAccountHttpClient('http://'.$address);$ready=false;
        for($i=0;$i<80;$i++){try{[$status,$body]=$client->request('/cashier.php');$ready=$status===200;if($ready)break;}catch(Throwable){}usleep(50000);}
        $check($ready,'server start');
        [$status,,$location]=$client->request('/admin/staff.php');$check($status===303&&$location==='/admin/login.php','unauthenticated staff management denied');
        [$status,$body]=$client->request('/admin/login.php');$csrf=KiosAccountHttpClient::field($body,'_csrf');
        [$status]=$client->request('/admin/login.php',['action'=>'login','password'=>'synthetic-owner-web-fixture','_csrf'=>$csrf]);$check($status===303,'owner valid authentication');
        [$status,$body]=$client->request('/admin/staff.php');$check($status===200&&str_contains($body,'Create staff account'),'owner management page');
        $fields=['action'=>'create','username'=>'ground-floor-test','display_name'=>'Synthetic operator','role'=>'ground_floor','enabled'=>'1','request_id'=>KiosAccountHttpClient::field($body,'request_id')];
        [$status]=$client->request('/admin/staff.php',$fields+['_csrf'=>'invalid']);$check($status===400,'owner CSRF denial');
        $fields['_csrf']=KiosAccountHttpClient::field($body,'_csrf');
        [$status,$body]=$client->request('/admin/staff.php',$fields);$check($status===200&&preg_match('/<code>([^<]+)<\/code>/',$body,$match)===1,'one-time generated credential');$temporary=html_entity_decode($match[1],ENT_QUOTES,'UTF-8');
        [$status,$repeat]=$client->request('/admin/staff.php',$fields);$check($status===400&&!str_contains($repeat,'<code>'),'mutation replay denied');
        [$status,$body]=$client->request('/admin/staff.php');$check($status===200&&!str_contains($body,'<code>'),'credential not redisplayed on GET');
        $first=new KiosAccountHttpClient('http://'.$address);[$status,$body]=$first->request('/cashier.php');$csrf=KiosAccountHttpClient::field($body,'_csrf');
        [$status]=$first->request('/cashier.php',['action'=>'login','username'=>'ground-floor-test','password'=>$temporary,'_csrf'=>$csrf]);$check($status===303,'staff valid login');
        [$status,$body]=$first->request('/cashier.php?station=SWEETS');$check($status===200&&str_contains($body,'Change password')&&!str_contains($body,'Start preparing'),'mandatory first-login gate');
        $csrf=KiosAccountHttpClient::field($body,'_csrf');
        [$status]=$first->request('/cashier.php',['action'=>'collect-order','token'=>'G-synthetic','_csrf'=>$csrf]);$check($status===403,'temporary-password account cannot perform work');
        $change=['action'=>'change-password','current_password'=>$temporary,'new_password'=>'synthetic-new-staff-password','confirm_password'=>'synthetic-new-staff-password'];
        [$status]=$first->request('/cashier.php',$change+['_csrf'=>'invalid']);$check($status===400,'staff password change CSRF denial');
        [$status]=$first->request('/cashier.php',$change+['_csrf'=>$csrf]);$check($status===303,'staff password change');
        [$status,$body]=$first->request('/cashier.php');$check($status===200&&str_contains($body,'<h1>Ground Floor</h1>'),'persisted Ground Floor dashboard');
        [$status]=$first->request('/cashier.php?station=SOUTH_INDIAN');$check($status===404,'cross-floor denial');
        [$status,,$location]=$first->request('/admin/staff.php');$check($status===303&&$location==='/admin/login.php','staff cannot become owner');
        $second=new KiosAccountHttpClient('http://'.$address);[$status,$body]=$second->request('/cashier.php');
        [$status]=$second->request('/cashier.php',['action'=>'login','username'=>'ground-floor-test','password'=>'synthetic-new-staff-password','_csrf'=>KiosAccountHttpClient::field($body,'_csrf')]);$check($status===303,'second staff session');
        $logoutClient=new KiosAccountHttpClient('http://'.$address);[$status,$body]=$logoutClient->request('/cashier.php');
        [$status]=$logoutClient->request('/cashier.php',['action'=>'login','username'=>'ground-floor-test','password'=>'synthetic-new-staff-password','_csrf'=>KiosAccountHttpClient::field($body,'_csrf')]);$check($status===303,'staff logout fixture login');
        [$status,$body]=$logoutClient->request('/cashier.php');[$status]=$logoutClient->request('/cashier.php',['action'=>'logout','_csrf'=>KiosAccountHttpClient::field($body,'_csrf')]);$check($status===303,'staff logout');
        [$status,$body]=$logoutClient->request('/cashier.php');$check(str_contains($body,'Sign in to staff operations')&&!str_contains($body,'Authorized demo staff session'),'staff logout invalidates current session');
        [$status,$body]=$client->request('/admin/staff.php');$reset=['action'=>'reset','account_id'=>KiosAccountHttpClient::field($body,'account_id'),'version'=>KiosAccountHttpClient::field($body,'version'),'request_id'=>KiosAccountHttpClient::field($body,'request_id'),'_csrf'=>KiosAccountHttpClient::field($body,'_csrf')];
        // Each form owns a nonce; the first current nonce can authorize only one submitted action.
        [$status]=$client->request('/admin/staff.php',$reset);$check($status===200,'authorized password reset');
        foreach([$first,$second] as $stale){[$status,$body]=$stale->request('/cashier.php');$check($status===200&&str_contains($body,'Sign in to staff operations')&&!str_contains($body,'Authorized demo staff session'),'reset revokes every staff session');}
        [$status,$body]=$client->request('/admin/staff.php');[$status]=$client->request('/admin/logout.php',['_csrf'=>KiosAccountHttpClient::field($body,'_csrf')]);$check($status===303,'owner logout');
        [$status,,$location]=$client->request('/admin/staff.php');$check($status===303&&$location==='/admin/login.php','owner session invalidated on logout');
    } finally {
        if(is_resource($process)){proc_terminate($process);proc_close($process);}
        $db=null;
        // Delete only flat, generated fixture files under the verified temporary directory.
        if(realpath(dirname($dir))===realpath(sys_get_temp_dir())&&str_starts_with(basename($dir),'kios-account-http-')){
            foreach(scandir($dir)?:[] as $name)if($name!=='.'&&$name!=='..'&&is_file($dir.DIRECTORY_SEPARATOR.$name))@unlink($dir.DIRECTORY_SEPARATOR.$name);
            @rmdir($dir);
        }
    }
}];
