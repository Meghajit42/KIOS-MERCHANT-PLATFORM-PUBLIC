<?php
declare(strict_types=1);

namespace Kios\MerchantFoundation;

use InvalidArgumentException;

final readonly class Membership
{
    public function __construct(
        public string $userId,
        public Role $role,
        public ?string $merchantId,
        public ?string $outletId,
        public bool $active = true,
    ) {
        Identity::validate($userId);
        if ($merchantId !== null) {
            Identity::validate($merchantId);
        }
        if ($outletId !== null) {
            Identity::validate($outletId);
        }
        $validScope = match ($role) {
            Role::PlatformAdministrator => $merchantId === null && $outletId === null,
            Role::MerchantOwner => $merchantId !== null && $outletId === null,
            default => $merchantId !== null && $outletId !== null,
        };
        if (!$validScope) {
            throw new InvalidArgumentException('Role requires an explicit valid scope.');
        }
    }
}