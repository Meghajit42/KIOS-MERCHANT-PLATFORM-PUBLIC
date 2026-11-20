<?php
declare(strict_types=1);
// Never routed by the app; only explicitly prepended by disposable CLI/loopback tests.
if(!in_array(PHP_SAPI,['cli','cli-server'],true))exit;
$dir=getenv('KIOS_ACCOUNT_HTTP_FIXTURE');
if(!is_string($dir)||!is_dir($dir)||!str_starts_with(basename($dir),'kios-account-http-')
    ||realpath(dirname($dir))!==realpath(sys_get_temp_dir())||is_link($dir))exit;
foreach(['APP_ENV'=>'local','KIOS_ACCOUNT_AUTH_ENABLED'=>'1','KIOS_AUTH_RATE_KEY'=>str_repeat('k',32),
    'KIOS_DB_HOST'=>'127.0.0.1','KIOS_DB_PORT'=>'33081','KIOS_DB_NAME'=>'kios_development',
    'KIOS_DB_USER'=>'synthetic','KIOS_DB_PASSWORD'=>'synthetic','PRINT_GATEWAY_ENABLED'=>'0','PRINT_DRIVER'=>'virtual'] as $k=>$v)putenv($k.'='.$v);
ini_set('session.save_path',$dir);ini_set('display_errors','0');ini_set('log_errors','0');
require dirname(__DIR__).'/app/bootstrap.php';
$property=new ReflectionProperty(Kios\Infrastructure\Database::class,'testConnector');
$property->setValue(null,static fn()=>new PDO('sqlite:'.$dir.'/fixture.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]));
