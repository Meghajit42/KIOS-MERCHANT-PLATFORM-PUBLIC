<?php
declare(strict_types=1);

namespace Kios\Application;

use InvalidArgumentException;

final class StationKotTextRenderer
{
    /** @param list<array{product_name_snapshot:string,variant_label_snapshot:string,quantity:int}> $items */
    public function render(
        string $outletName,
        string $stationName,
        string $stationCode,
        string $token,
        string $orderType,
        string $createdAt,
        array $items,
    ): string {
        if ($token === '' || $stationCode === '' || $items === []) {
            throw new InvalidArgumentException('A station KOT requires a token, station and item snapshots.');
        }

        $lines = [
            PrintText::line($outletName),
            'KITCHEN ORDER TICKET',
            'TOKEN ' . PrintText::line($token),
            '--------------------------------',
            'STATION: ' . strtoupper(PrintText::line($stationName)),
            'ORDER TYPE: ' . strtoupper(PrintText::line($orderType)),
            'TIME: ' . PrintText::line($createdAt),
            '--------------------------------',
        ];
        foreach ($items as $item) {
            $quantity = (int) $item['quantity'];
            if ($quantity < 1) {
                throw new InvalidArgumentException('A KOT item has an invalid quantity.');
            }
            $lines[] = $quantity . ' x ' . PrintText::line($item['product_name_snapshot']);
            $lines[] = '  ' . PrintText::line($item['variant_label_snapshot']);
        }

        return implode("\n", $lines) . "\n";
    }
}
