<?php
declare(strict_types=1);
// Trusted guest-only fixture for concurrent duplicate requests; never an HTTP route.
require 'C:/KiosB0/app/bootstrap.php';
use Kios\Infrastructure\{Database,Environment};
use Kios\MerchantFoundation\AuthenticatedUserProvider;
use Kios\MerchantOperations\MerchantProvisioner;
if(PHP_SAPI!=='cli'||Environment::get('APP_ENV','')!=='local'||!in_array($argv[1]??'', ['a','b'],true))exit(1);
try {
    $fixture=json_decode(file_get_contents('C:/M02AConcurrent.json'),true,32,JSON_THROW_ON_ERROR);
    $auth=new class implements AuthenticatedUserProvider {public function currentUserId():?string{return '00000000-0000-4000-8000-000000000100';}};
    $result=(new MerchantProvisioner(Database::connect(),$auth))->provision($fixture['request'],$fixture['input']);
    file_put_contents('C:/M02AConcurrent-'.$argv[1].'.json',json_encode($result,JSON_THROW_ON_ERROR));
}catch(Throwable $error){exit(1);}
