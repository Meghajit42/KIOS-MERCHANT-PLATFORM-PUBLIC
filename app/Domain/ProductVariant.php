<?php
declare(strict_types=1);

namespace Kios\Domain;

use InvalidArgumentException;

final readonly class ProductVariant
{
    public function __construct(
        public string $id,
        public string $label,
        public QuantityUnit $unit,
        public int $quantityValue,
        public Money $unitPrice,
        public bool $active = true,
    ) {
        if (trim($id) === '' || trim($label) === '' || $quantityValue < 1) {
            throw new InvalidArgumentException('Variant ID, label and positive quantity are required.');
        }
    }
}
