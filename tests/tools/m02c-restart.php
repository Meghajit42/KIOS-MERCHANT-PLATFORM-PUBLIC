<?php
declare(strict_types=1);
require 'C:/KiosB0/app/bootstrap.php';
use Kios\Infrastructure\Database;
use Kios\MerchantFoundation\AuthenticatedUserProvider;
use Kios\MerchantOperations\RestaurantSetup;
$c=json_decode(file_get_contents('C:/M02CRestart.json'),true,32,JSON_THROW_ON_ERROR);
$auth=new class($c['user']) implements AuthenticatedUserProvider{public function __construct(private string $id){}public function currentUserId():?string{return $this->id;}};
$s=(new RestaurantSetup(Database::connect(),$auth))->load($c['merchant'],$c['outlet']);$ok=$s['version']===$c['version']&&count($s['products'])===$c['products'];
file_put_contents('C:/Mapped/Results/m02c-restart-result.json',json_encode(['status'=>$ok?'PASS':'FAIL','newPhpProcess'=>true,'version'=>$s['version'],'products'=>count($s['products'])],JSON_THROW_ON_ERROR));exit($ok?0:1);
