<?php
declare(strict_types=1);

namespace Kios\MerchantFoundation;

use InvalidArgumentException;

/**
 * Immutable server-owned registry for the disabled foundation.
 * Persistence adapters must enforce the same identity and ownership invariants.
 */
final readonly class Directory implements DirectoryProvider
{
    private array $merchants;
    private array $outlets;
    private array $memberships;

    public function __construct(array $merchants, array $outlets, array $memberships)
    {
        $merchantIndex = [];
        foreach ($merchants as $merchant) {
            if (!$merchant instanceof Merchant || isset($merchantIndex[$merchant->id])) {
                throw new InvalidArgumentException('Invalid or duplicate merchant.');
            }
            $merchantIndex[$merchant->id] = $merchant;
        }
        $outletIndex = [];
        foreach ($outlets as $outlet) {
            if (!$outlet instanceof Outlet || isset($outletIndex[$outlet->id])
                || !isset($merchantIndex[$outlet->merchantId])) {
                throw new InvalidArgumentException('Invalid outlet ownership or duplicate identity.');
            }
            $outletIndex[$outlet->id] = $outlet;
        }
        $membershipIndex = [];
        foreach ($memberships as $membership) {
            if (!$membership instanceof Membership) {
                throw new InvalidArgumentException('Invalid membership.');
            }
            if ($membership->merchantId !== null && !isset($merchantIndex[$membership->merchantId])) {
                throw new InvalidArgumentException('Membership merchant does not exist.');
            }
            if ($membership->outletId !== null
                && ($outletIndex[$membership->outletId]->merchantId ?? null) !== $membership->merchantId) {
                throw new InvalidArgumentException('Membership outlet belongs to another merchant.');
            }
            $key = implode('|', [$membership->userId, $membership->merchantId ?? '', $membership->outletId ?? '']);
            if (isset($membershipIndex[$key])) {
                throw new InvalidArgumentException('Conflicting duplicate membership scope.');
            }
            $membershipIndex[$key] = $membership;
        }
        $this->merchants = $merchantIndex;
        $this->outlets = $outletIndex;
        $this->memberships = array_values($membershipIndex);
    }

    public function forUser(string $userId): Directory
    {
        Identity::validate($userId);
        return $this;
    }

    public function merchant(string $id): ?Merchant
    {
        return $this->merchants[$id] ?? null;
    }

    public function outlet(string $id): ?Outlet
    {
        return $this->outlets[$id] ?? null;
    }

    public function membershipsFor(string $userId): array
    {
        return array_values(array_filter(
            $this->memberships,
            static fn(Membership $membership): bool => $membership->userId === $userId && $membership->active,
        ));
    }
}