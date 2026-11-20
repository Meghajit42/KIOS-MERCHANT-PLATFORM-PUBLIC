<?php
declare(strict_types=1);
require 'C:/KiosB0/app/bootstrap.php';
use Kios\MerchantFoundation\{AuthenticatedUserProvider, AuthorizationService, PdoIdentityStore, Permission};
try {
    $db=new PDO('mysql:host=127.0.0.1;port=33081;dbname=kios_development','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $auth=new class implements AuthenticatedUserProvider {
        public function currentUserId(): ?string { return '00000000-0000-4000-8000-000000000102'; }
    };
    $s=new AuthorizationService($auth,new PdoIdentityStore($db));
    $s->requireResource(Permission::ReadOrders,'00000000-0000-4000-8000-000000000001',
        '00000000-0000-4000-8000-000000000011','00000000-0000-4000-8000-000000000001',
        '00000000-0000-4000-8000-000000000011');
    if((int)$db->query('SELECT COUNT(*) FROM merchant_organizations')->fetchColumn()!==3)throw new RuntimeException('restart count');
    file_put_contents('C:/Mapped/Results/restart-result.json',json_encode(['status'=>'PASS','newPhpProcess'=>true,'merchants'=>3,'authorizationRestored'=>true]));
}catch(Throwable $e){
    file_put_contents('C:/Mapped/Results/restart-result.json',json_encode(['status'=>'FAIL','errorClass'=>get_class($e)]));exit(1);
}