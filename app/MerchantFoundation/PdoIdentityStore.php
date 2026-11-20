<?php
declare(strict_types=1);

namespace Kios\MerchantFoundation;

use DomainException;
use LogicException;
use PDO;
use Throwable;

/** Internal server-owned database port. No public routes or production auth adapter. */
final class PdoIdentityStore implements DirectoryProvider
{
    private bool $ownsTransaction = false;

    public function __construct(private readonly PDO $db)
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new LogicException('MariaDB PDO connection required.');
        }
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    private function rows(string $sql, array $values = []): array
    {
        $query = $this->db->prepare($sql);
        $query->execute($values);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    private function verifyPermissionMatrix(): void
    {
        $expected = [];
        foreach (Role::cases() as $role) {
            foreach (Permission::cases() as $permission) {
                if ($role->allows($permission)) {
                    $expected[] = $role->value . ':' . $permission->value;
                }
            }
        }
        $actual = array_map(
            static fn(array $row): string => $row['role_code'] . ':' . $row['permission_code'],
            $this->rows('SELECT role_code,permission_code FROM merchant_role_permissions'),
        );
        sort($expected);
        sort($actual);
        if ($actual !== $expected) {
            throw new DomainException('Permission policy requires reviewed compatibility.');
        }
    }

    public function forUser(string $userId): Directory
    {
        if ($this->db->inTransaction() && !$this->ownsTransaction) {
            throw new LogicException('Authorization cannot use a caller-owned transaction snapshot.');
        }
        Identity::validate($userId);
        $this->verifyPermissionMatrix();
        if ($this->rows('SELECT user_id FROM merchant_staff WHERE user_id=? AND active=1', [$userId]) === []) {
            return new Directory([], [], []);
        }
        $memberships = [];
        foreach ($this->rows(
            'SELECT merchant_id,NULL AS outlet_id,role_code FROM merchant_memberships WHERE user_id=? AND active=1
             UNION ALL SELECT merchant_id,outlet_id,role_code FROM merchant_outlet_memberships WHERE user_id=? AND active=1
             UNION ALL SELECT NULL,NULL,role_code FROM merchant_platform_memberships WHERE user_id=? AND active=1',
            [$userId, $userId, $userId],
        ) as $row) {
            $memberships[] = new Membership(
                $userId, Role::from($row['role_code']), $row['merchant_id'], $row['outlet_id'],
            );
        }
        $merchants = [];
        $outlets = [];
        foreach ($memberships as $membership) {
            if ($membership->merchantId === null) {
                continue;
            }
            $merchantId = $membership->merchantId;
            foreach ($this->rows('SELECT * FROM merchant_organizations WHERE merchant_id=?', [$merchantId]) as $row) {
                $merchants[$row['merchant_id']] = new Merchant($row['merchant_id'], $row['name'], (bool)$row['active']);
            }
            $sql = 'SELECT * FROM merchant_outlets WHERE merchant_id=?';
            $values = [$merchantId];
            if ($membership->outletId !== null) {
                $sql .= ' AND outlet_id=?';
                $values[] = $membership->outletId;
            }
            foreach ($this->rows($sql, $values) as $row) {
                $outlets[$row['outlet_id']] = new Outlet(
                    $row['outlet_id'], $row['merchant_id'], $row['name'], (bool)$row['active'],
                );
            }
        }
        return new Directory(array_values($merchants), array_values($outlets), $memberships);
    }

    /**
     * Owns the complete transaction. Locks serialize staff activity and grant changes.
     * Provisioning and revocation integrations must obey the same staff-row locking protocol.
     */
    private function transaction(AuthenticatedUserProvider $auth, string $targetUserId, callable $action): mixed
    {
        if ($this->db->inTransaction()) {
            throw new LogicException('Identity operation requires its own transaction.');
        }
        $actor = $auth->currentUserId();
        if ($actor === null) {
            throw new DomainException('Authentication required.');
        }
        Identity::validate($actor);
        Identity::validate($targetUserId);
        $this->db->beginTransaction();
        $this->ownsTransaction = true;
        try {
            $ids = array_unique([$actor, $targetUserId]);
            sort($ids);
            foreach ($ids as $id) {
                $this->rows('SELECT user_id FROM merchant_staff WHERE user_id=? FOR UPDATE', [$id]);
            }
            $bound = new class($actor) implements AuthenticatedUserProvider {
                public function __construct(private readonly string $userId) {}
                public function currentUserId(): ?string { return $this->userId; }
            };
            $result=$action($bound);
            $this->db->commit();return $result;
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        } finally {
            $this->ownsTransaction = false;
        }
    }

    public function createMerchant(AuthenticatedUserProvider $auth, Merchant $merchant): void
    {
        $this->transaction($auth, $auth->currentUserId() ?? '', function ($bound) use ($merchant): void {
            (new AuthorizationService($bound, $this))->requirePlatformAdministration();
            $this->rows('INSERT INTO merchant_organizations(merchant_id,name,active) VALUES(?,?,?)',
                [$merchant->id, $merchant->name, (int)$merchant->active]);
        });
    }

    public function createOutlet(AuthenticatedUserProvider $auth, Outlet $outlet): void
    {
        $this->transaction($auth, $auth->currentUserId() ?? '', function ($bound) use ($outlet): void {
            (new AuthorizationService($bound, $this))->requirePlatformAdministration();
            $this->rows('INSERT INTO merchant_outlets(outlet_id,merchant_id,name,active) VALUES(?,?,?,?)',
                [$outlet->id, $outlet->merchantId, $outlet->name, (int)$outlet->active]);
        });
    }

    public function createStaff(AuthenticatedUserProvider $auth, string $userId, string $name): void
    {
        Identity::validate($userId);
        if (trim($name) === '' || strlen($name) > 200) {
            throw new DomainException('Staff display name required and bounded.');
        }
        $this->transaction($auth, $userId, function ($bound) use ($userId, $name): void {
            (new AuthorizationService($bound, $this))->requirePlatformAdministration();
            $this->rows('INSERT INTO merchant_staff(user_id,display_name) VALUES(?,?)', [$userId, $name]);
        });
    }

    public function assignMembership(AuthenticatedUserProvider $auth, Membership $membership): void
    {
        $this->transaction($auth, $membership->userId, function ($bound) use ($membership): void {
            $directory = $this->forUser($bound->currentUserId());
            if ($membership->merchantId !== null) {
                $merchant = $this->rows('SELECT active FROM merchant_organizations WHERE merchant_id=? FOR UPDATE',
                    [$membership->merchantId]);
                if ($merchant === [] || !(bool)$merchant[0]['active']) {
                    throw new DomainException('Inactive or missing merchant.');
                }
            }
            if ($membership->outletId !== null) {
                $outlet = $this->rows('SELECT active FROM merchant_outlets WHERE merchant_id=? AND outlet_id=? FOR UPDATE',
                    [$membership->merchantId, $membership->outletId]);
                if ($outlet === [] || !(bool)$outlet[0]['active']) {
                    throw new DomainException('Outlet ownership denied.');
                }
            }
            $allowed = false;
            foreach ($directory->membershipsFor($bound->currentUserId()) as $grant) {
                if ($grant->role === Role::PlatformAdministrator) {
                    $allowed = true;
                }
                if ($grant->role === Role::MerchantOwner && $grant->merchantId === $membership->merchantId) {
                    $allowed = true;
                }
                if ($grant->role === Role::OutletAdministrator && $grant->merchantId === $membership->merchantId
                    && $grant->outletId === $membership->outletId
                    && in_array($membership->role, [Role::OutletAdministrator, Role::Cashier, Role::Kitchen], true)) {
                    $allowed = true;
                }
            }
            if (!$allowed || $this->rows('SELECT user_id FROM merchant_staff WHERE user_id=? AND active=1',
                [$membership->userId]) === []) {
                throw new DomainException('Membership assignment denied.');
            }
            if ($membership->role === Role::PlatformAdministrator) {
                $this->rows('INSERT INTO merchant_platform_memberships(user_id,role_code,active) VALUES(?,?,?)',
                    [$membership->userId, $membership->role->value, (int)$membership->active]);
            } elseif ($membership->outletId === null) {
                $this->rows('INSERT INTO merchant_memberships(user_id,merchant_id,role_code,active) VALUES(?,?,?,?)',
                    [$membership->userId, $membership->merchantId, $membership->role->value, (int)$membership->active]);
            } else {
                $this->rows('INSERT INTO merchant_outlet_memberships(user_id,merchant_id,outlet_id,role_code,active) VALUES(?,?,?,?,?)',
                    [$membership->userId, $membership->merchantId, $membership->outletId, $membership->role->value,
                        (int)$membership->active]);
            }
        });
    }
    /** Platform-only scoped role bundles. Guard and audit run in this native locked identity transaction. */
    public function manageOutletAdministrator(AuthenticatedUserProvider $auth,string $merchant,string $outlet,string $target,Role $role,bool $active,\Closure $guard,\Closure $audit):array
    {
        Identity::validate($merchant);Identity::validate($outlet);Identity::validate($target);
        if(!in_array($role,[Role::OutletAdministrator,Role::Cashier,Role::Kitchen],true))throw new DomainException('Only reviewed outlet role bundles are supported.');
        return $this->transaction($auth,$target,function($bound)use($merchant,$outlet,$target,$role,$active,$guard,$audit):array{
            (new AuthorizationService($bound,$this))->requirePlatformAdministration();$actor=$bound->currentUserId();
            $prior=$guard($this->db,$actor);if($prior!==null)return $prior;
            if(!$this->rows('SELECT o.outlet_id FROM merchant_outlets o JOIN merchant_organizations m ON m.merchant_id=o.merchant_id AND m.active=1 WHERE o.merchant_id=? AND o.outlet_id=? AND o.active=1 FOR UPDATE',[$merchant,$outlet]))throw new DomainException('Active outlet ownership required.');
            $links=$this->rows('SELECT l.user_id FROM merchant_auth_links l JOIN merchant_legacy_outlets o ON o.legacy_outlet_id=l.legacy_outlet_id JOIN staff_accounts a ON a.account_id=l.account_id AND a.outlet_id=l.legacy_outlet_id JOIN merchant_staff s ON s.user_id=l.user_id WHERE l.user_id=? AND o.merchant_id=? AND l.active=1 AND a.realm=\'staff\' AND a.enabled=1 AND a.must_change=0 AND s.active=1 FOR UPDATE',[$target,$merchant]);
            if(!$links||$this->rows('SELECT l.user_id FROM merchant_auth_links l JOIN merchant_legacy_outlets o ON o.legacy_outlet_id=l.legacy_outlet_id WHERE l.user_id=? AND o.merchant_id<>? FOR UPDATE',[$target,$merchant])
                ||$this->rows('SELECT user_id FROM merchant_memberships WHERE user_id=? FOR UPDATE',[$target])
                ||$this->rows('SELECT user_id FROM merchant_platform_memberships WHERE user_id=? FOR UPDATE',[$target])
                ||$this->rows('SELECT user_id FROM merchant_financial_grants WHERE user_id=? FOR UPDATE',[$target])
                ||$this->rows('SELECT actor_user_id FROM merchant_worker_grants WHERE actor_user_id=? FOR UPDATE',[$target]))throw new DomainException('Use an existing native staff identity from this merchant with no owner, platform, financial or worker authority.');
            $before=$this->rows('SELECT role_code,active FROM merchant_outlet_memberships WHERE user_id=? AND merchant_id=? AND outlet_id=? FOR UPDATE',[$target,$merchant,$outlet]);
            $this->rows('INSERT INTO merchant_outlet_memberships(user_id,merchant_id,outlet_id,role_code,active) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE role_code=VALUES(role_code),active=VALUES(active)',[$target,$merchant,$outlet,$role->value,(int)$active]);
            return $audit($this->db,$actor,$before[0]??null,['role'=>$role->value,'active'=>$active]);
        });
    }

}