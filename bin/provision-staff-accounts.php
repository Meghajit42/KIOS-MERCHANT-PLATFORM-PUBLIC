<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
use Kios\Infrastructure\{Database,DatabaseConfiguration,Environment,StaffAccountService};
$options=getopt('',['operation-id:','confirm-database:']);
try{
    $env=Environment::get('APP_ENV','local');$config=DatabaseConfiguration::fromEnvironment('KIOS_DB');
    if(!is_string($options['operation-id']??null)||($options['confirm-database']??null)!==$config->database
        ||!in_array($env,['local','demo'],true)||($env==='demo'&&($config->database!=='hosting_account_kiosdemo'
        ||Environment::get('KIOS_AUTH_P0_RELEASE_APPROVED')!=='K10_AUTH_P0_RELEASE_APPROVED')))
        throw new RuntimeException('Explicit cutover approval and target are required.');
    (new StaffAccountService(Database::connect()))->provisionLegacy($options['operation-id']);
    echo "Existing roles provisioned as cashier, ground-floor and first-floor. Password change is required on first login.\n";
}catch(Throwable){fwrite(STDERR,"Provisioning did not complete. No credential values emitted; inspect protected audit and account state before retrying.\n");exit(1);}
