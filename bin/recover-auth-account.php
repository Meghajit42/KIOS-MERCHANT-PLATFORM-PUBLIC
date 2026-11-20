<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
use Kios\Infrastructure\{Database,DatabaseConfiguration,Environment,StaffAccountService};

// Recipient key is public. The matching private key stays on the operator's computer.
$options=getopt('',['target:','operation-id:','recipient-public-key:','encrypted-output:','confirm-database:']);
try {
    if(!function_exists('sodium_crypto_box_seal'))throw new RuntimeException('Recovery encryption runtime is unavailable.');
    foreach(['target','operation-id','recipient-public-key','encrypted-output','confirm-database'] as $k)if(!is_string($options[$k]??null))throw new RuntimeException('Recovery arguments are incomplete.');
    $config=DatabaseConfiguration::fromEnvironment('KIOS_DB');
    $env=Environment::get('APP_ENV','local');
    if(!StaffAccountService::enabled()||!in_array($env,['local','demo'],true)||$options['confirm-database']!==$config->database
        ||($env==='demo'&&($config->database!=='hosting_account_kiosdemo'||Environment::get('KIOS_AUTH_RECOVERY_APPROVED')!=='K10_AUTH_OWNER_APPROVED')))
        throw new RuntimeException('Recovery target or operator authorization is invalid.');
    $public=base64_decode($options['recipient-public-key'],true);
    if(!is_string($public)||strlen($public)!==SODIUM_CRYPTO_BOX_PUBLICKEYBYTES)throw new RuntimeException('Recipient public key is invalid.');
    $path=$options['encrypted-output'];$dir=realpath(dirname($path));$repo=realpath(dirname(__DIR__));
    if(!$dir||!$repo||is_link(dirname($path))||file_exists($path)||str_starts_with(strtolower($dir.DIRECTORY_SEPARATOR),strtolower($repo.DIRECTORY_SEPARATOR)))
        throw new RuntimeException('Choose a new encrypted output outside the application tree.');
    $privateBase=realpath(dirname(__DIR__,2));
    if($env==='demo'&&(!$privateBase||!str_starts_with($dir.DIRECTORY_SEPARATOR,$privateBase.DIRECTORY_SEPARATOR)))throw new RuntimeException('Staging recovery delivery must remain inside the dedicated private parent.');
    if(preg_match('~[\\/](public_html|htdocs|www|public)([\\/]|$)~i',$dir))throw new RuntimeException('Recovery delivery cannot use a public directory.');
    if(PHP_OS_FAMILY!=='Windows'&&((fileperms($dir)&0777)!==0700||fileowner($dir)!==posix_geteuid()))throw new RuntimeException('Recovery output directory must be private and owned by this operator.');
    // Reserve delivery before changing a credential. It is never a plaintext file.
    $stream=fopen($path,'x');if(!$stream)throw new RuntimeException('Could not reserve encrypted delivery.');$reserved=true;chmod($path,0600);
    $service=new StaffAccountService(Database::connect());
    $result=$service->recover($options['target'],$options['operation-id'],static function(array $result)use($public,$stream):void{
        $sealed=sodium_crypto_box_seal(json_encode($result,JSON_THROW_ON_ERROR),$public);
        if(fwrite($stream,$sealed)!==strlen($sealed)||!fflush($stream))throw new RuntimeException('Encrypted delivery failed.');
    });
    fclose($stream);unset($result);$complete=true;
    echo "Recovery completed. Use the recipient private key locally to open the one-time encrypted delivery.\n";
} catch(Throwable) {
    // Never echo exception arguments, configuration or credential data.
    if(isset($stream)&&is_resource($stream))fclose($stream);
    if(($reserved??false)&&!($complete??false)&&isset($path))@unlink($path);
    fwrite(STDERR,"Recovery did not complete normally. Review the protected audit and operation id before any new operation.\n");exit(1);
}
