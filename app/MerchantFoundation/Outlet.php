<?php
declare(strict_types=1);

namespace Kios\MerchantFoundation;

use InvalidArgumentException;

final readonly class Outlet
{
    public function __construct(
        public string $id,
        public string $merchantId,
        public string $name,
        public bool $active = true,
    ) {
        Identity::validate($id);
        Identity::validate($merchantId);
        if (trim($name) === '' || strlen($name) > 200) {
            throw new InvalidArgumentException('Outlet name is required and bounded.');
        }
    }
}