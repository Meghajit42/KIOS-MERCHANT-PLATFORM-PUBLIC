<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
require __DIR__.'/PersistenceTest.php';
$dir=$argv[1]??'';
if(!is_dir($dir)||!str_starts_with(basename($dir),'kios-account-http-'))exit(3);
[$db]=kiosTestPersistence('sqlite:'.$dir.'/fixture.sqlite');
$sql=file_get_contents(dirname(__DIR__).'/database/migrations/011_staff_accounts.sql');
$sql=preg_replace('/ CHARACTER SET ascii COLLATE ascii_bin/','',$sql);
$sql=preg_replace('/UNIQUE KEY [a-z_]+/','UNIQUE',$sql);
$sql=preg_replace('/^\s*KEY [^\n]+\n/m','',$sql);
$db->exec(str_replace(' ENGINE=InnoDB','',$sql));
$db->prepare("INSERT INTO staff_accounts VALUES (?,1,'owner','owner','Owner','owner',?,1,0,1,?,?)")->execute([str_repeat('e',32),password_hash('synthetic-owner-web-fixture',PASSWORD_DEFAULT),time(),time()]);
echo 'PASS';
