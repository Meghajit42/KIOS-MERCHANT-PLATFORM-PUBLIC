<?php
declare(strict_types=1);

namespace Kios\Domain;

use InvalidArgumentException;

final readonly class OrderItem
{
    public function __construct(
        public string $productId,
        public string $variantId,
        public string $name,
        public string $variantLabel,
        public QuantityUnit $unit,
        public int $quantity,
        public Money $unitPrice,
    ) {
        if (trim($productId) === '' || trim($variantId) === '' || trim($name) === '' || trim($variantLabel) === '' || $quantity < 1) {
            throw new InvalidArgumentException('Order item details and a positive quantity are required.');
        }
    }

    public function lineTotal(): Money
    {
        return $this->unitPrice->multiply($this->quantity);
    }
}
