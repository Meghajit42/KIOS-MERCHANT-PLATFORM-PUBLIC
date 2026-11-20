<?php
declare(strict_types=1);
require 'C:/KiosB0/app/bootstrap.php';
$c=json_decode(file_get_contents('C:/M03BRestart.json'),true,32,JSON_THROW_ON_ERROR);
$auth=new class($c['actor']) implements \Kios\MerchantFoundation\AuthenticatedUserProvider{public function __construct(private string $id){}public function currentUserId():?string{return $this->id;}};
$db=new PDO('mysql:host=127.0.0.1;port=33081;dbname=kios_development','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$actual=(new \Kios\MerchantOperations\BrandProfile($db,$auth))->layout($c['merchant'],$c['outlet']);
exit($actual===$c['expected']?0:1);
