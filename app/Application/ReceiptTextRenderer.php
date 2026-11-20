<?php
declare(strict_types=1);

namespace Kios\Application;

use InvalidArgumentException;
use Kios\Domain\QuantityUnit;

final class ReceiptTextRenderer
{
    /** @param list<array{product_name_snapshot:string,variant_label_snapshot:string,unit_snapshot:string,quantity:int,unit_price_paise:int,line_total_paise:int}> $items */
    public function render(
        string $outletName,
        string $token,
        string $orderType,
        int $totalPaise,
        string $paymentMethod,
        string $confirmedAt,
        array $items,
        string $copyLabel = 'CUSTOMER COPY',
    ): string {
        if ($token === '' || $items === [] || !in_array($copyLabel, ['CUSTOMER COPY', 'COUNTER COPY'], true)) {
            throw new InvalidArgumentException('A receipt requires an order token and item snapshots.');
        }

        $lines = [
            PrintText::line($outletName),
            'CASHIER RECEIPT',
            $copyLabel,
            'TOKEN ' . PrintText::line($token),
            '--------------------------------',
            'ORDER TYPE: ' . strtoupper(PrintText::line($orderType)),
            'STATUS: PAYMENT CONFIRMED',
            'PAYMENT: ' . strtoupper(PrintText::line($paymentMethod)),
            'TIME: ' . PrintText::line($confirmedAt),
            '--------------------------------',
        ];
        foreach ($items as $item) {
            $quantity = (int) $item['quantity'];
            if ($quantity < 1) {
                throw new InvalidArgumentException('A receipt item has an invalid quantity.');
            }
            $unit = QuantityUnit::tryFrom((string) $item['unit_snapshot']);
            $variantLabel = PrintText::line((string) $item['variant_label_snapshot']);
            if ($unit === null || $variantLabel === '') {
                throw new InvalidArgumentException('A receipt item has an invalid unit snapshot.');
            }
            $lines[] = $quantity . ' x ' . PrintText::line($item['product_name_snapshot']);
            $lines[] = '  ' . $variantLabel;
            $lines[] = '  UNIT PRICE: ' . PrintText::money((int) $item['unit_price_paise']) . ' / ' . $this->priceUnitLabel($unit, $variantLabel);
            $lines[] = '  LINE TOTAL: ' . PrintText::money((int) $item['line_total_paise']);
        }
        $lines[] = '--------------------------------';
        $lines[] = 'TOTAL: ' . PrintText::money($totalPaise);
        $lines[] = 'PAYMENT CONFIRMED';

        return implode("\n", $lines) . "\n";
    }

    /** @param list<array{product_name_snapshot:string,variant_label_snapshot:string,unit_snapshot:string,quantity:int,unit_price_paise:int,line_total_paise:int}> $items
     *  @return list<array{copyLabel:string,text:string}> */
    public function renderCopies(
        string $outletName,
        string $token,
        string $orderType,
        int $totalPaise,
        string $paymentMethod,
        string $confirmedAt,
        array $items,
    ): array {
        $copies = [];
        foreach (['CUSTOMER COPY', 'COUNTER COPY'] as $copyLabel) {
            $copies[] = [
                'copyLabel' => $copyLabel,
                'text' => $this->render($outletName, $token, $orderType, $totalPaise, $paymentMethod, $confirmedAt, $items, $copyLabel),
            ];
        }
        return $copies;
    }

    private function priceUnitLabel(QuantityUnit $unit, string $variantLabel): string
    {
        return match ($unit) {
            QuantityUnit::Gram, QuantityUnit::Piece, QuantityUnit::Plate, QuantityUnit::Count => $variantLabel,
            QuantityUnit::Portion => preg_match('/\b(?:portion|servings?)\b/i', $variantLabel) === 1
                ? $variantLabel
                : $variantLabel . ' portion',
        };
    }
}
