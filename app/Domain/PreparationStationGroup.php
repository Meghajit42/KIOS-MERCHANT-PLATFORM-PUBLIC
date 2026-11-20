<?php

declare(strict_types=1);

namespace Kios\Domain;

use InvalidArgumentException;

final readonly class PreparationStationGroup
{
    /** @var list<OrderItem> */
    public readonly array $items;

    /** @param list<OrderItem> $items */
    public function __construct(
        public readonly PreparationStationCode $station,
        array $items,
    ) {
        if ($items === []) {
            throw new InvalidArgumentException('The list of items must not be empty.');
        }

        foreach ($items as $item) {
            if (!$item instanceof OrderItem) {
                throw new InvalidArgumentException('All elements in the items list must be OrderItem instances.');
            }
        }

        $this->items = $items;
    }
}
