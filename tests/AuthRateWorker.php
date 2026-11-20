<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('KIOS_AUTH_DISPOSABLE_DB')!=='AUTH_P0_DISPOSABLE_GUEST')exit(2);
require dirname(__DIR__).'/app/bootstrap.php';
try{
    $guest=getenv('KIOS_AUTH_TEST_GUEST');
    if(!is_string($guest)||!is_file($guest.'/disposable.marker')||!str_starts_with(realpath(dirname(__DIR__)),realpath($guest).DIRECTORY_SEPARATOR))exit(3);
    $deadline=microtime(true)+10;while(!is_file($guest.'/rate-workers.go')&&microtime(true)<$deadline)usleep(10000);
    if(!is_file($guest.'/rate-workers.go'))exit(4);
    $s=new Kios\Infrastructure\StaffAccountService(Kios\Infrastructure\Database::connect(),'demo-outlet',str_repeat('q',32));
    for($i=0;$i<3;$i++)if($s->authenticate('mariadb-concurrent','synthetic-wrong-input','concurrent-peer')!==null)exit(5);
    echo 'PASS';
}catch(Throwable){fwrite(STDERR,'AUTH_RATE_WORKER_FAILED');exit(1);}
