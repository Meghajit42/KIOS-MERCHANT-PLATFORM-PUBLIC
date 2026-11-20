<?php
declare(strict_types=1);
require 'C:/KiosB0/app/bootstrap.php';
use Kios\MerchantFoundation\{Identity, Merchant, Outlet, Membership, Role, Permission, Directory, DirectoryProvider, PdoIdentityStore, AuthorizationService, AuthenticatedUserProvider};
use Kios\Infrastructure\MigrationRunner;
$result=['passed'=>0,'failed'=>0,'cases'=>[]];
function idb(int $n): string { return '00000000-0000-4000-8000-'.str_pad((string)$n,12,'0',STR_PAD_LEFT); }
function authb(int $id): AuthenticatedUserProvider {
    return new class(idb($id)) implements AuthenticatedUserProvider {
        public function __construct(private string $id) {}
        public function currentUserId(): ?string { return $this->id; }
    };
}
function checkb(bool $ok): void { if(!$ok)throw new RuntimeException('assertion'); }
function deniedb(callable $operation):void {
    try{$operation();}catch(DomainException|InvalidArgumentException|PDOException $expected){return;}
    throw new RuntimeException('expected denial');
}
function caseb(string $name, callable $operation):void {
    global $result;
    try{$operation();$result['passed']++;$result['cases'][$name]='PASS';}
    catch(Throwable $e){$result['failed']++;$result['cases'][$name]=get_class($e).':'.$e->getCode();}
}
$db=new PDO('mysql:host=127.0.0.1;port=33081;dbname=kios_development','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
caseb('013 applies once after original twelve migrations',function()use($db){
    checkb((new MigrationRunner($db))->apply('C:/KiosB0/database/migrations')===['013_merchant_identity.sql']);
    checkb((new MigrationRunner($db))->apply('C:/KiosB0/database/migrations')===[]);
});
$admin=authb(100);
$db->prepare('INSERT INTO merchant_staff(user_id,display_name) VALUES(?,?)')->execute([idb(100),'Synthetic platform operator']);
$db->prepare('INSERT INTO merchant_platform_memberships(user_id) VALUES(?)')->execute([idb(100)]);
$store=new PdoIdentityStore($db);
caseb('persist three merchants and multiple owned outlets',function()use($store,$admin){
    foreach([1,2,3] as $n){
        $store->createMerchant($admin,new Merchant(idb($n),'Synthetic merchant '.$n));
        foreach([1,2] as $j)$store->createOutlet($admin,new Outlet(idb($n*10+$j),idb($n),'Synthetic outlet '.$n.$j));
    }
});
caseb('persist staff and explicit merchant outlet memberships',function()use($store,$admin){
    foreach([101,102,103,104,105,106,107,108] as $n)$store->createStaff($admin,idb($n),'Synthetic staff '.$n);
    $store->assignMembership($admin,new Membership(idb(101),Role::MerchantOwner,idb(1),null));
    $store->assignMembership($admin,new Membership(idb(102),Role::Cashier,idb(1),idb(11)));
    $store->assignMembership($admin,new Membership(idb(103),Role::Kitchen,idb(2),idb(21)));
    $store->assignMembership($admin,new Membership(idb(104),Role::OutletAdministrator,idb(1),idb(11)));
});
caseb('persistent and in-memory authorization are equivalent for all permissions',function()use($store){
    $memory=new Directory([new Merchant(idb(1),'Synthetic')],[new Outlet(idb(11),idb(1),'Synthetic')],
        [new Membership(idb(102),Role::Cashier,idb(1),idb(11))]);
    foreach(Permission::cases() as $permission){
        $outcomes=[];
        foreach([$memory,$store] as $provider){
            try{(new AuthorizationService(authb(102),$provider))->requireResource($permission,idb(1),idb(11),idb(1),idb(11));$outcomes[]=true;}
            catch(DomainException $e){$outcomes[]=false;}
        }
        checkb($outcomes[0]===$outcomes[1]);
    }
});
caseb('owner authority spans own outlets only',function()use($store){
    $s=new AuthorizationService(authb(101),$store);
    $s->requireResource(Permission::ManageOutlet,idb(1),idb(12),idb(1),idb(12));
    deniedb(fn()=>$s->requireResource(Permission::ReadOrders,idb(2),idb(21),idb(2),idb(21)));
});
caseb('cashier and kitchen roles deny forbidden operational actions',function()use($store){
    $s=new AuthorizationService(authb(102),$store);
    deniedb(fn()=>$s->requireResource(Permission::PrepareOrders,idb(1),idb(11),idb(1),idb(11)));
    $s=new AuthorizationService(authb(103),$store);
    deniedb(fn()=>$s->requireResource(Permission::RecordCash,idb(2),idb(21),idb(2),idb(21)));
});
caseb('foreign record and wrong outlet identifiers deny access',function()use($store){
    $s=new AuthorizationService(authb(102),$store);
    deniedb(fn()=>$s->requireResource(Permission::ReadOrders,idb(1),idb(11),idb(2),idb(21)));
    deniedb(fn()=>$s->requireResource(Permission::ReadOrders,idb(1),idb(12),idb(1),idb(12)));
    deniedb(fn()=>$s->requireResource(Permission::ReadOrders,idb(1),idb(21),idb(1),idb(21)));
});
caseb('unprovisioned or unauthenticated identity denies authority',function()use($store){
    deniedb(fn()=>(new AuthorizationService(authb(999),$store))->requireResource(Permission::ReadOrders,idb(1),idb(11),idb(1),idb(11)));
});
caseb('cashier cannot assign memberships or provision merchants',function()use($store){
    deniedb(fn()=>$store->assignMembership(authb(102),new Membership(idb(105),Role::Cashier,idb(1),idb(11))));
    deniedb(fn()=>$store->createMerchant(authb(102),new Merchant(idb(4),'Synthetic denied')));
});
caseb('merchant owner cannot grant foreign merchant or platform authority',function()use($store){
    deniedb(fn()=>$store->assignMembership(authb(101),new Membership(idb(105),Role::Cashier,idb(2),idb(21))));
    deniedb(fn()=>$store->assignMembership(authb(101),new Membership(idb(105),Role::PlatformAdministrator,null,null)));
});
caseb('outlet admin can grant own outlet role but cannot elevate to merchant owner',function()use($store){
    $store->assignMembership(authb(104),new Membership(idb(105),Role::Kitchen,idb(1),idb(11)));
    deniedb(fn()=>$store->assignMembership(authb(104),new Membership(idb(106),Role::MerchantOwner,idb(1),null)));
    deniedb(fn()=>$store->assignMembership(authb(104),new Membership(idb(106),Role::Cashier,idb(1),idb(12))));
});
caseb('database composite foreign key rejects cross-merchant outlet membership',function()use($db){
    deniedb(function()use($db){$db->prepare("INSERT INTO merchant_outlet_memberships VALUES(?,?,?,'cashier',1)")->execute([idb(106),idb(1),idb(21)]);});
});
caseb('database staff foreign key and role scope checks deny invalid grants',function()use($db){
    deniedb(function()use($db){$db->prepare("INSERT INTO merchant_memberships VALUES(?,?,'merchant_owner',1)")->execute([idb(999),idb(1)]);});
    deniedb(function()use($db){$db->prepare("INSERT INTO merchant_memberships VALUES(?,?,'cashier',1)")->execute([idb(106),idb(1)]);});
});
caseb('duplicate immutable identities and duplicate membership fail',function()use($store,$admin){
    deniedb(fn()=>$store->createMerchant($admin,new Merchant(idb(1),'Collision')));
    deniedb(fn()=>$store->assignMembership($admin,new Membership(idb(102),Role::Cashier,idb(1),idb(11))));
});
caseb('failed grant rolls back and leaves transaction usable',function()use($db,$store,$admin){
    $before=$db->query('SELECT COUNT(*) FROM merchant_outlet_memberships')->fetchColumn();
    deniedb(fn()=>$store->assignMembership($admin,new Membership(idb(107),Role::Kitchen,idb(1),idb(21))));
    checkb(!$db->inTransaction() && $db->query('SELECT COUNT(*) FROM merchant_outlet_memberships')->fetchColumn()===$before);
});
caseb('live membership revocation is observed by existing authorization service',function()use($db,$store){
    $s=new AuthorizationService(authb(102),$store);
    $db->prepare('UPDATE merchant_outlet_memberships SET active=0 WHERE user_id=?')->execute([idb(102)]);
    deniedb(fn()=>$s->requireResource(Permission::ReadOrders,idb(1),idb(11),idb(1),idb(11)));
    $db->prepare('UPDATE merchant_outlet_memberships SET active=1 WHERE user_id=?')->execute([idb(102)]);
});
caseb('inactive staff and merchant deny existing authority',function()use($db,$store){
    $s=new AuthorizationService(authb(102),$store);
    $db->prepare('UPDATE merchant_staff SET active=0 WHERE user_id=?')->execute([idb(102)]);
    deniedb(fn()=>$s->requireResource(Permission::ReadOrders,idb(1),idb(11),idb(1),idb(11)));
    $db->prepare('UPDATE merchant_staff SET active=1 WHERE user_id=?')->execute([idb(102)]);
    $db->prepare('UPDATE merchant_organizations SET active=0 WHERE merchant_id=?')->execute([idb(1)]);
    deniedb(fn()=>$s->requireResource(Permission::ReadOrders,idb(1),idb(11),idb(1),idb(11)));
    deniedb(fn()=>$store->assignMembership(authb(101),new Membership(idb(107),Role::Cashier,idb(1),idb(11))));
    $db->prepare('UPDATE merchant_organizations SET active=1 WHERE merchant_id=?')->execute([idb(1)]);
});
caseb('overlapping synthetic product keys remain scoped in converted fixture only',function()use($db,$store){
    $db->exec('CREATE TEMPORARY TABLE m01b_synthetic_records(merchant_id CHAR(36),outlet_id CHAR(36),sku VARCHAR(40),PRIMARY KEY(merchant_id,outlet_id,sku))');
    $q=$db->prepare('INSERT INTO m01b_synthetic_records VALUES(?,?,?)');
    $q->execute([idb(1),idb(11),'SAME-SKU']);$q->execute([idb(2),idb(21),'SAME-SKU']);
    $context=(new AuthorizationService(authb(102),$store))->requireResource(Permission::ReadOrders,idb(1),idb(11),idb(1),idb(11));
    $q=$db->prepare('SELECT sku FROM m01b_synthetic_records WHERE merchant_id=? AND outlet_id=?');
    $q->execute([$context->merchantId,$context->outletId]);checkb(count($q->fetchAll())===1);
});
caseb('persisted permission policy drift fails closed',function()use($db,$store){
    $db->exec("DELETE FROM merchant_role_permissions WHERE role_code='kitchen' AND permission_code='read_orders'");
    deniedb(fn()=>$store->forUser(idb(103)));
    $db->exec("INSERT INTO merchant_role_permissions VALUES('kitchen','read_orders')");
});
caseb('database rejects malformed stable identity',function()use($db){
    deniedb(fn()=>$db->exec("INSERT INTO merchant_organizations VALUES('bad-id','Synthetic invalid',1)"));
});
caseb('inactive outlet rejects context',function()use($db,$store){
    $db->prepare('UPDATE merchant_outlets SET active=0 WHERE outlet_id=?')->execute([idb(11)]);
    deniedb(fn()=>(new AuthorizationService(authb(102),$store))->requireResource(Permission::ReadOrders,idb(1),idb(11),idb(1),idb(11)));
    $db->prepare('UPDATE merchant_outlets SET active=1 WHERE outlet_id=?')->execute([idb(11)]);
});
caseb('caller transaction snapshot cannot supply stale authorization',function()use($db,$store){
    $db->beginTransaction();
    try {
        try {
            $store->forUser(idb(102));
        } catch (LogicException $expected) {
            return;
        }
        throw new RuntimeException('caller snapshot accepted');
    } finally {
        $db->rollBack();
    }
});
file_put_contents('C:/Mapped/Results/persistence-result.json',json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
exit($result['failed']===0?0:1);