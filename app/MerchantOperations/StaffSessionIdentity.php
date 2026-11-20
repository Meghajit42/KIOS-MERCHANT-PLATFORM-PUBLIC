<?php
declare(strict_types=1);
namespace Kios\MerchantOperations;

use PDO;
use Kios\Infrastructure\StaffAccountService;
use Kios\MerchantFoundation\AuthenticatedUserProvider;

/** Session values are obtained exclusively from PHP server-side session storage. */
final class StaffSessionIdentity implements AuthenticatedUserProvider
{
    public function __construct(
        private readonly PDO $db,
        private readonly \Closure $session,
        private readonly string $rateKey,
    ) {
    }

    public function currentUserId(): ?string
    {
        $session = ($this->session)();
        $account = $session['_staff_account_id'] ?? null;
        $outlet = $session['_merchant_login_outlet'] ?? null;
        if (!is_string($account) || !is_int($outlet) || $outlet < 1) {
            return null;
        }
        $sql = 'SELECT l.user_id,o.outlet_code FROM merchant_auth_links l
            JOIN outlets o ON o.outlet_id=l.legacy_outlet_id AND o.active=1
            JOIN merchant_staff u ON u.user_id=l.user_id AND u.active=1
            JOIN staff_accounts a ON a.account_id=l.account_id AND a.outlet_id=l.legacy_outlet_id
            WHERE l.account_id=? AND l.legacy_outlet_id=? AND l.active=1';
        if ($this->db->inTransaction()) {
            $sql .= ' FOR UPDATE';
        }
        $q = $this->db->prepare($sql);
        $q->execute([$account,$outlet]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $identity = (new StaffAccountService($this->db,$row['outlet_code'],$this->rateKey))->identity($session);
        if ($identity === null || (int)$identity['must_change'] !== 0) {
            return null;
        }
        return $row['user_id'];
    }
}