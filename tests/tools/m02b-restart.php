<?php
declare(strict_types=1);
require 'C:/KiosB0/app/bootstrap.php';
use Kios\Infrastructure\Database;
use Kios\MerchantFoundation\AuthenticatedUserProvider;
use Kios\MerchantOperations\OwnerOnboarding;
$c=json_decode(file_get_contents('C:/M02BRestart.json'),true,32,JSON_THROW_ON_ERROR);
$auth=new class($c['user']) implements AuthenticatedUserProvider {public function __construct(private string $user){}public function currentUserId():?string{return $this->user;}};
$s=(new OwnerOnboarding(Database::connect(),$auth))->load($c['merchant']);
$ok=$s['progress']===$c['progress']&&(int)$s['version']===$c['version'];
file_put_contents('C:/Mapped/Results/m02b-restart-result.json',json_encode(['status'=>$ok?'PASS':'FAIL','newPhpProcess'=>true,'progress'=>$s['progress'],'version'=>$s['version']],JSON_THROW_ON_ERROR));exit($ok?0:1);
