<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use Kios\Infrastructure\{Database,Environment};
use Kios\MerchantOperations\VirtualPrintWorker;
if (PHP_SAPI!=='cli' || Environment::get('KIOS_MERCHANT_DEV_ENABLED','0')!=='1'
    || Environment::get('APP_ENV','local')!=='local') {
    exit(1);
}
try {
    $worker=new VirtualPrintWorker(Database::connect(),getenv('KIOS_WORKER_ID')?:'',getenv('KIOS_WORKER_CREDENTIAL')?:'');
    $status=$worker->deliver($argv[1]??'',$argv[2]??'',(int)($argv[3]??0));
    echo $status->value."\n";
} catch (Throwable $error) {
    fwrite(STDERR,"Service operation denied.\n");exit(1);
}