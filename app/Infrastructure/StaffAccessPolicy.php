<?php
declare(strict_types=1);

namespace Kios\Infrastructure;

final class StaffAccessPolicy
{
    public static function allows(bool $hostedDemo, string $remoteAddress, bool $https): bool
    {
        if ($hostedDemo) {
            return $https;
        }
        return in_array($remoteAddress, ['127.0.0.1', '::1'], true);
    }
}
