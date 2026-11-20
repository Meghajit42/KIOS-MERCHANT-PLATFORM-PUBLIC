<?php

declare(strict_types=1);

namespace Kios\Domain;

use DomainException;

final class PreparationRouter
{
    /** @param array<string, string> $stationByProductId @return list<PreparationStationGroup> */
    public static function routePaidOrder(Order $order, array $stationByProductId): array
    {
        if ($order->status !== OrderStatus::Paid) {
            throw new DomainException('Order status must be paid before preparation routing.');
        }

        return PreparationGrouper::group($order->items, $stationByProductId);
    }
}
