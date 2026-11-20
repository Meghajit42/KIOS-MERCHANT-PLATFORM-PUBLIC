<?php
declare(strict_types=1);

namespace Kios\MerchantFoundation;

use DomainException;

/** Resolved from current authenticated authority, never deserialized from a client. */
final readonly class TenantContext
{
    private function __construct(
        public string $userId,
        public string $merchantId,
        public string $outletId,
        private array $roles,
    ) {
    }

    public static function resolve(
        AuthenticatedUserProvider $authentication,
        DirectoryProvider $directory,
        string $requestedMerchantId,
        string $requestedOutletId,
    ): self {
        Identity::validate($requestedMerchantId);
        Identity::validate($requestedOutletId);
        $userId = $authentication->currentUserId();
        if ($userId === null) {
            throw new DomainException('Authentication required.');
        }
        Identity::validate($userId);
        $directory = $directory->forUser($userId);
        $merchant = $directory->merchant($requestedMerchantId);
        $outlet = $directory->outlet($requestedOutletId);
        if ($merchant === null || !$merchant->active || $outlet === null || !$outlet->active
            || $outlet->merchantId !== $merchant->id) {
            throw new DomainException('Tenant access denied.');
        }
        $roles = [];
        foreach ($directory->membershipsFor($userId) as $membership) {
            if ($membership->merchantId === $merchant->id
                && ($membership->outletId === null || $membership->outletId === $outlet->id)) {
                $roles[] = $membership->role;
            }
        }
        if ($roles === []) {
            throw new DomainException('Tenant access denied.');
        }
        return new self($userId, $merchant->id, $outlet->id, $roles);
    }

    public function allows(Permission $permission): bool
    {
        foreach ($this->roles as $role) {
            if ($role->allows($permission)) {
                return true;
            }
        }
        return false;
    }
}