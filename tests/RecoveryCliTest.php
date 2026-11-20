<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/bootstrap.php';
use Kios\Infrastructure\StaffAccountService;

return ['actual recovery CLI seals delivery rejects replay and preserves explicit staff target permissions'=>static function(string $root):void{
    if(!function_exists('sodium_crypto_box_seal'))throw new RuntimeException('CLI recovery tests require sodium');
    $dir=sys_get_temp_dir().DIRECTORY_SEPARATOR.'kios-account-http-'.bin2hex(random_bytes(10));mkdir($dir,0700);$db=null;
    $php=[PHP_BINARY,'-n','-d','extension_dir='.ini_get('extension_dir')];
    foreach(['pdo_sqlite','pdo_mysql','gd','fileinfo','mbstring','sodium'] as $ext)$php=array_merge($php,['-d','extension=php_'.$ext.'.dll']);
    if(PHP_OS_FAMILY!=='Windows')$php=[PHP_BINARY];
    $env=[];foreach(['SystemRoot','WINDIR','PATH','PATHEXT','TEMP','TMP','COMSPEC'] as $k)if(is_string(getenv($k)))$env[$k]=getenv($k);$env['KIOS_ACCOUNT_HTTP_FIXTURE']=$dir;
    $run=static function(array $command)use($root,$env):array{
        $p=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root,$env);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return [proc_close($p),$out,$err];
    };
    $check=static function(bool $ok,string $label){if(!$ok)throw new RuntimeException('CLI recovery fixture: '.$label);};
    try{
        [$code,$out,$err]=$run(array_merge($php,[$root.'/tests/AccountFixtureSetup.php',$dir]));$check($code===0&&trim($out)==='PASS'&&$err==='','setup');
        $keys=sodium_crypto_box_keypair();$pub=base64_encode(sodium_crypto_box_publickey($keys));
        $db=new PDO('sqlite:'.$dir.'/fixture.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $s=new StaffAccountService($db,'demo-outlet',str_repeat('k',32));$oldTag=$s->ownerTag();
        $base=array_merge($php,['-d','auto_prepend_file='.$root.'/tests/AccountServerBootstrap.php',$root.'/bin/recover-auth-account.php']);
        $op=bin2hex(random_bytes(16));$args=['--target=owner','--operation-id='.$op,'--recipient-public-key='.$pub,'--encrypted-output='.$dir.'/owner.box','--confirm-database=kios_development'];
        [$code,$out,$err]=$run(array_merge($base,$args));$check($code===0&&$err==='','owner CLI completed');
        $opened=sodium_crypto_box_seal_open(file_get_contents($dir.'/owner.box'),$keys);$check(is_string($opened),'encrypted delivery opened');$data=json_decode($opened,true,512,JSON_THROW_ON_ERROR);
        $check(password_verify($data['password'],$s->ownerHash())&&$oldTag!==$s->ownerTag(),'owner hash replacement and revocation');
        $check(!str_contains($out,$data['password'])&&!str_contains($err,$data['password']),'no credential output');
        $args[3]='--encrypted-output='.$dir.'/replay.box';[$code]=$run(array_merge($base,$args));$check($code!==0&&!file_exists($dir.'/replay.box'),'one-time operation replay rejected');
        $owner=['menu_admin_authenticated'=>true,'menu_admin_actor'=>'menu-admin','_owner_account_tag'=>$s->ownerTag()];
        $a=$s->create($owner,'disabled-operator','Disabled operator','ground_floor',false);
        $args=['--target=staff:'.$a['accountId'],'--operation-id='.bin2hex(random_bytes(16)),'--recipient-public-key='.$pub,'--encrypted-output='.$dir.'/staff.box','--confirm-database=kios_development'];
        [$code,$out,$err]=$run(array_merge($base,$args));$check($code===0&&$err==='','explicit staff recovery');
        $staff=json_decode(sodium_crypto_box_seal_open(file_get_contents($dir.'/staff.box'),$keys),true,512,JSON_THROW_ON_ERROR);
        $check($staff['identity']['role']==='ground_floor'&&(int)$staff['identity']['enabled']===0&&(int)$staff['identity']['must_change']===1,'role/disabled state preserved');
        $args[0]='--target=unspecified';$args[1]='--operation-id='.bin2hex(random_bytes(16));$args[3]='--encrypted-output='.$dir.'/invalid.box';
        [$code]=$run(array_merge($base,$args));$check($code!==0&&!file_exists($dir.'/invalid.box'),'invalid explicit target rejected');
    }finally{
        $db=null;
        if(realpath(dirname($dir))===realpath(sys_get_temp_dir())&&str_starts_with(basename($dir),'kios-account-http-')){
            foreach(scandir($dir)?:[] as $name)if($name!=='.'&&$name!=='..'&&is_file($dir.DIRECTORY_SEPARATOR.$name))@unlink($dir.DIRECTORY_SEPARATOR.$name);@rmdir($dir);
        }
    }
}];
