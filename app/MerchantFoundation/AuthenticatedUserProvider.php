<?php
declare(strict_types=1);

namespace Kios\MerchantFoundation;

/**
 * Trusted server-side authentication adapter.
 * Implementations must verify the current session and revocation on every call.
 * Request parameters, unsigned headers and client cookies cannot supply this identity.
 * No production adapter or public route is enabled by M01A.
 */
interface AuthenticatedUserProvider
{
    public function currentUserId(): ?string;
}