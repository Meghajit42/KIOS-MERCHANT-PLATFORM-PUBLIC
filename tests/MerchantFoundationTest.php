<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

use Kios\MerchantFoundation\{Identity, Merchant, Outlet, Membership, Role, Permission, Directory, TenantContext, AuthenticatedUserProvider, AuthorizationService};

function m01id(int $n): string
{
    return '00000000-0000-4000-8000-' . str_pad((string)$n, 12, '0', STR_PAD_LEFT);
}
function m01check(bool $condition): void
{
    if (!$condition) {
        throw new RuntimeException('Foundation assertion failed.');
    }
}
function m01deny(callable $action): void
{
    try {
        $action();
    } catch (DomainException|InvalidArgumentException $error) {
        return;
    }
    throw new RuntimeException('Expected denial.');
}
function m01fixture(Role $role = Role::Cashier): array
{
    $a = new Merchant(m01id(1), 'Synthetic merchant A');
    $b = new Merchant(m01id(2), 'Synthetic merchant B');
    $a1 = new Outlet(m01id(11), $a->id, 'Synthetic A1');
    $a2 = new Outlet(m01id(12), $a->id, 'Synthetic A2');
    $b1 = new Outlet(m01id(21), $b->id, 'Synthetic B1');
    $auth = new class implements AuthenticatedUserProvider {
        public ?string $user = null;
        public function currentUserId(): ?string { return $this->user; }
    };
    $auth->user = m01id(101);
    $membership = new Membership(
        $auth->user, $role, $role === Role::PlatformAdministrator ? null : $a->id,
        in_array($role, [Role::MerchantOwner, Role::PlatformAdministrator], true) ? null : $a1->id,
    );
    $directory = new Directory([$a, $b], [$a1, $a2, $b1], [$membership]);
    return [$auth, $directory, new AuthorizationService($auth, $directory), $a, $b, $a1, $a2, $b1];
}
return [
    'generated identities are canonical distinct immutable values' => static function (): void {
        $ids = [];
        for ($i = 0; $i < 100; $i++) {
            $id = Identity::generate();
            Identity::validate($id);
            $ids[] = $id;
        }
        m01check(count(array_unique($ids)) === 100);
        $merchant = new Merchant(m01id(1), 'Synthetic');
        try {
            $merchant->id = m01id(2);
        } catch (Error $e) {
            return;
        }
        throw new RuntimeException('Identity was mutable.');
    },
    'malformed identifiers and empty names are rejected' => static function (): void {
        foreach (['', '1', '../tenant', str_repeat('x', 500), strtoupper('abcdefab-abcd-4abc-8abc-abcdefabcdef')] as $id) {
            m01deny(fn() => new Merchant($id, 'Synthetic'));
        }
        m01deny(fn() => new Merchant(m01id(1), ' '));
        m01deny(fn() => new Outlet(m01id(11), m01id(1), ''));
    },
    'directory rejects duplicate merchant outlet and dangling ownership records' => static function (): void {
        $a = new Merchant(m01id(1), 'Synthetic');
        $o = new Outlet(m01id(11), $a->id, 'Synthetic');
        m01deny(fn() => new Directory([$a, $a], [], []));
        m01deny(fn() => new Directory([$a], [$o, $o], []));
        m01deny(fn() => new Directory([], [$o], []));
        m01deny(fn() => new Directory(['arbitrary'], [], []));
    },
    'memberships enforce role scope and merchant outlet ownership' => static function (): void {
        m01deny(fn() => new Membership(m01id(101), Role::Cashier, m01id(1), null));
        m01deny(fn() => new Membership(m01id(101), Role::PlatformAdministrator, m01id(1), null));
        m01deny(fn() => new Membership(m01id(101), Role::MerchantOwner, m01id(1), m01id(11)));
        [$auth, $d, $service, $a, $b, $a1, $a2, $b1] = m01fixture();
        $bad = new Membership($auth->user, Role::Kitchen, $a->id, $b1->id);
        m01deny(fn() => new Directory([$a, $b], [$a1, $b1], [$bad]));
        m01deny(fn() => new Directory([], [], [new Membership(m01id(101), Role::MerchantOwner, m01id(999), null)]));
    },
    'conflicting memberships are rejected rather than silently elevating privilege' => static function (): void {
        $a = new Merchant(m01id(1), 'Synthetic');
        $o = new Outlet(m01id(11), $a->id, 'Synthetic');
        $one = new Membership(m01id(101), Role::Cashier, $a->id, $o->id);
        $two = new Membership(m01id(101), Role::OutletAdministrator, $a->id, $o->id);
        m01deny(fn() => new Directory([$a], [$o], [$one, $two]));
    },
    'all role permissions match an explicit least privilege matrix' => static function (): void {
        $expected = [
            'platform_administrator' => ['manage_platform'],
            'merchant_owner' => ['manage_merchant','manage_outlet','manage_staff','read_orders','record_cash','prepare_orders'],
            'outlet_administrator' => ['manage_outlet','manage_staff','read_orders','record_cash','prepare_orders'],
            'cashier' => ['read_orders','record_cash'],
            'kitchen' => ['read_orders','prepare_orders'],
        ];
        foreach (Role::cases() as $role) {
            foreach (Permission::cases() as $permission) {
                m01check($role->allows($permission) === in_array($permission->value, $expected[$role->value], true));
            }
        }
        m01check(Role::tryFrom('future_superuser') === null);
        m01check(Permission::tryFrom('arbitrary_action') === null);
    },
    'arbitrary requested merchant identifiers cannot grant tenant access' => static function (): void {
        [$auth, $d, $s, $a, $b, $a1, $a2, $b1] = m01fixture();
        $context = $s->requireResource(Permission::ReadOrders, $a->id, $a1->id, $a->id, $a1->id);
        m01check($context->userId === $auth->user && $context->merchantId === $a->id);
        m01deny(fn() => $s->requireResource(Permission::ReadOrders, $b->id, $b1->id, $b->id, $b1->id));
        m01deny(fn() => TenantContext::resolve($auth, $d, $a->id, $b1->id));
        m01deny(fn() => TenantContext::resolve($auth, $d, m01id(999), $a1->id));
    },
    'same merchant wrong outlet and foreign protected resource are denied' => static function (): void {
        [$auth, $d, $s, $a, $b, $a1, $a2, $b1] = m01fixture();
        m01deny(fn() => $s->requireResource(Permission::ReadOrders, $a->id, $a2->id, $a->id, $a2->id));
        m01deny(fn() => $s->requireResource(Permission::ReadOrders, $a->id, $a1->id, $b->id, $b1->id));
        m01deny(fn() => $s->requireResource(Permission::ReadOrders, $a->id, $a1->id, $a->id, $a2->id));
        m01deny(fn() => $s->requireResource(Permission::ManageStaff, $a->id, $a1->id, $a->id, $a1->id));
    },
    'merchant owner spans own outlets without crossing merchant boundary' => static function (): void {
        [$auth, $d, $s, $a, $b, $a1, $a2, $b1] = m01fixture(Role::MerchantOwner);
        m01check($s->requireResource(Permission::ManageOutlet, $a->id, $a2->id, $a->id, $a2->id)->outletId === $a2->id);
        m01deny(fn() => $s->requireResource(Permission::ManageMerchant, $b->id, $b1->id, $b->id, $b1->id));
        m01deny(fn() => $s->requirePlatformAdministration());
    },
    'platform administration does not implicitly grant merchant resource access' => static function (): void {
        [$auth, $d, $s, $a, $b, $a1] = m01fixture(Role::PlatformAdministrator);
        $s->requirePlatformAdministration();
        m01deny(fn() => $s->requireResource(Permission::ReadOrders, $a->id, $a1->id, $a->id, $a1->id));
    },
    'authentication loss and changed subject revoke authorization on next operation' => static function (): void {
        [$auth, $d, $s, $a, $b, $a1] = m01fixture();
        $s->requireResource(Permission::ReadOrders, $a->id, $a1->id, $a->id, $a1->id);
        $auth->user = null;
        m01deny(fn() => $s->requireResource(Permission::ReadOrders, $a->id, $a1->id, $a->id, $a1->id));
        $auth->user = m01id(999);
        m01deny(fn() => $s->requireResource(Permission::ReadOrders, $a->id, $a1->id, $a->id, $a1->id));
    },
    'inactive merchants outlets and memberships deny access' => static function (): void {
        $auth = new class implements AuthenticatedUserProvider {
            public function currentUserId(): ?string { return m01id(101); }
        };
        foreach (['merchant','outlet','membership'] as $disabled) {
            $a = new Merchant(m01id(1), 'Synthetic', $disabled !== 'merchant');
            $o = new Outlet(m01id(11), $a->id, 'Synthetic', $disabled !== 'outlet');
            $membership = new Membership(m01id(101), Role::Cashier, $a->id, $o->id, $disabled !== 'membership');
            $d = new Directory([$a], [$o], [$membership]);
            m01deny(fn() => TenantContext::resolve($auth, $d, $a->id, $o->id));
        }
    },
    'membership removal in new server snapshot denies formerly authorized subject' => static function (): void {
        [$auth, $d, $s, $a, $b, $a1] = m01fixture();
        $s->requireResource(Permission::ReadOrders, $a->id, $a1->id, $a->id, $a1->id);
        $revoked = new AuthorizationService($auth, new Directory([$a], [$a1], []));
        m01deny(fn() => $revoked->requireResource(Permission::ReadOrders, $a->id, $a1->id, $a->id, $a1->id));
    },
    'foundation stays unwired from all legacy runtime entry points' => static function (string $root): void {
        foreach (['public', 'app/Application', 'app/Infrastructure'] as $dir) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir)) as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    m01check(!str_contains((string)file_get_contents($file->getPathname()), 'MerchantFoundation'));
                }
            }
        }
    },
];