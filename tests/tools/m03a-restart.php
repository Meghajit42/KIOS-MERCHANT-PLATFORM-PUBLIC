<?php
declare(strict_types=1);
require 'C:/KiosB0/app/bootstrap.php';
use Kios\MerchantFoundation\AuthenticatedUserProvider;
use Kios\MerchantOperations\BrandProfile;
$c=json_decode(file_get_contents('C:/M03ARestart.json'),true,32,JSON_THROW_ON_ERROR);
$auth=new class($c['actor']) implements AuthenticatedUserProvider{public function __construct(private string $id){}public function currentUserId():?string{return $this->id;}};
$db=new PDO('mysql:host=127.0.0.1;port=33081;dbname=kios_development','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$state=(new BrandProfile($db,$auth))->load($c['merchant'],$c['outlet']);$pass=$state===$c['expected'];
file_put_contents('C:/Mapped/Results/m03a-restart-result.json',json_encode(['status'=>$pass?'PASS':'FAIL','newProcess'=>true],JSON_THROW_ON_ERROR));exit($pass?0:1);
