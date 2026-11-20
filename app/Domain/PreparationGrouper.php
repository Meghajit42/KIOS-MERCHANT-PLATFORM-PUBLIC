<?php

declare(strict_types=1);

namespace Kios\Domain;

use DomainException;
use InvalidArgumentException;

final class PreparationGrouper
{
    /** @param list<OrderItem> $items @param array<string, string> $stationByProductId @return list<PreparationStationGroup> */
    public static function group(array $items, array $stationByProductId): array
    {
        if ($items === []) {
            throw new InvalidArgumentException('Item list cannot be empty.');
        }

        $groups = [];
        foreach ($items as $item) {
            if (!$item instanceof OrderItem) {
                throw new InvalidArgumentException('All items must be instances of OrderItem.');
            }
            if (!isset($stationByProductId[$item->productId])) {
                throw new DomainException("Missing mapping for product ID: {$item->productId}");
            }

            $stationCode = $stationByProductId[$item->productId];
            if (!is_string($stationCode) || $stationCode === '') {
                throw new DomainException("Invalid station code for product ID: {$item->productId}");
            }
            $groups[$stationCode][] = $item;
        }

        ksort($groups, SORT_STRING);
        $result = [];
        foreach ($groups as $code => $bucket) {
            $result[] = new PreparationStationGroup(new PreparationStationCode((string) $code), $bucket);
        }
        return $result;
    }
}
