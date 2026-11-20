<?php
declare(strict_types=1);
namespace Kios\MerchantFoundation;

/** Server-owned provider. Every call must observe current membership and staff activity. */
interface DirectoryProvider
{
    public function forUser(string $userId): Directory;
}