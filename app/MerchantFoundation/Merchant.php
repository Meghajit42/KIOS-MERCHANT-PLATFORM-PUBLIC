<?php
declare(strict_types=1);

namespace Kios\MerchantFoundation;

use InvalidArgumentException;

final readonly class Merchant
{
    public function __construct(public string $id, public string $name, public bool $active = true)
    {
        Identity::validate($id);
        if (trim($name) === '' || strlen($name) > 200) {
            throw new InvalidArgumentException('Merchant name is required and bounded.');
        }
    }
}