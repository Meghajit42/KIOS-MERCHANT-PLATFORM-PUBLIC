<?php
declare(strict_types=1);
namespace Kios\MerchantOperations;

use PDO;
use DomainException;
use Kios\MerchantFoundation\{AuthenticatedUserProvider,Identity};

/** Original owner authority is retained; only the selected, verified outlet mapping varies. */
final class OwnerOutletScope
{
    public static function prepare(PDO $db,AuthenticatedUserProvider $auth,string $m,string $o):array
    {
        Identity::validate($m);Identity::validate($o);$s=(new OwnerOnboarding($db,$auth))->load($m);
        if($s['merchant_outlet_id']!==$o&&!IntegratedSetup::enabled())throw new DomainException('Secondary outlet setup is disabled.');
        $q=$db->prepare('SELECT l.legacy_outlet_id,o.name FROM merchant_legacy_outlets l JOIN merchant_outlets o ON o.merchant_id=l.merchant_id AND o.outlet_id=l.merchant_outlet_id AND o.active=1 JOIN outlets lo ON lo.outlet_id=l.legacy_outlet_id AND lo.active=1 WHERE l.merchant_id=? AND l.merchant_outlet_id=?');$q->execute([$m,$o]);$r=$q->fetch(PDO::FETCH_ASSOC);if(!$r)throw new DomainException('Active mapped owner outlet required.');
        return array_replace($s,['initial_outlet_id'=>$s['merchant_outlet_id'],'initial_legacy_outlet_id'=>$s['legacy_outlet_id'],'merchant_outlet_id'=>$o,'legacy_outlet_id'=>(int)$r['legacy_outlet_id'],'selected_outlet_name'=>$r['name']]);
    }
    public static function locked(PDO $db,AuthenticatedUserProvider $auth,string $m,string $o,string $owner):array
    {
        if(!$db->inTransaction()||$auth->currentUserId()!==$owner)throw new DomainException('Current original owner identity required.');
        $q=$db->prepare("SELECT o.name,l.legacy_outlet_id,lo.outlet_code FROM merchant_onboarding p
            JOIN merchant_memberships mm ON mm.merchant_id=p.merchant_id AND mm.user_id=p.owner_user_id AND mm.role_code='merchant_owner' AND mm.active=1
            JOIN merchant_staff u ON u.user_id=p.owner_user_id AND u.active=1
            JOIN merchant_organizations m ON m.merchant_id=p.merchant_id AND m.active=1
            JOIN merchant_outlets o ON o.merchant_id=p.merchant_id AND o.outlet_id=? AND o.active=1
            JOIN merchant_legacy_outlets l ON l.merchant_id=o.merchant_id AND l.merchant_outlet_id=o.outlet_id
            JOIN outlets lo ON lo.outlet_id=l.legacy_outlet_id AND lo.active=1
            JOIN staff_accounts a ON a.account_id=p.owner_account_id AND a.outlet_id=p.legacy_outlet_id AND a.enabled=1 AND a.must_change=0
            JOIN merchant_auth_links al ON al.account_id=a.account_id AND al.user_id=p.owner_user_id AND al.legacy_outlet_id=p.legacy_outlet_id AND al.active=1
            WHERE p.merchant_id=? AND p.owner_user_id=? AND p.status='active' FOR UPDATE");$q->execute([$o,$m,$owner]);$r=$q->fetch(PDO::FETCH_ASSOC);if(!$r)throw new DomainException('Current native owner, account and outlet authority required.');return$r;
    }
}
