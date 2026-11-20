<?php
declare(strict_types=1);

namespace Kios\MerchantFoundation;

use DomainException;

/**
 * One service per server-owned authentication provider and current directory snapshot.
 * Resolve authority again for every operation to observe identity or membership revocation.
 * Resource scope must come from trusted stored metadata, never request fields.
 */
final readonly class AuthorizationService
{
    public function __construct(
        private AuthenticatedUserProvider $authentication,
        private DirectoryProvider $directory,
    ) {
    }

    public function requireResource(
        Permission $permission,
        string $requestedMerchantId,
        string $requestedOutletId,
        string $resourceMerchantId,
        string $resourceOutletId,
    ): TenantContext {
        $context = TenantContext::resolve(
            $this->authentication, $this->directory, $requestedMerchantId, $requestedOutletId,
        );
        if ($context->merchantId !== $resourceMerchantId || $context->outletId !== $resourceOutletId
            || !$context->allows($permission)) {
            throw new DomainException('Resource access denied.');
        }
        return $context;
    }

    public function requirePlatformAdministration(): void
    {
        $userId = $this->authentication->currentUserId();
        if ($userId === null) {
            throw new DomainException('Authentication required.');
        }
        Identity::validate($userId);
        foreach ($this->directory->forUser($userId)->membershipsFor($userId) as $membership) {
            if ($membership->role === Role::PlatformAdministrator) {
                return;
            }
        }
        throw new DomainException('Platform access denied.');
    }
}