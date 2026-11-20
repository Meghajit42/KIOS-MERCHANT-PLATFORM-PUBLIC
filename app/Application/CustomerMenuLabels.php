<?php
declare(strict_types=1);

namespace Kios\Application;

use Kios\Domain\Product;
use Kios\Domain\ProductVariant;
use Kios\Domain\QuantityUnit;

/** Customer-facing language for variant choices and serving quantities. */
final class CustomerMenuLabels
{
    public static function option(Product $product, ProductVariant $variant): string
    {
        if ($variant->unit === QuantityUnit::Portion && $product->categoryId !== 'beverage') {
            return '1 Plate';
        }
        return $variant->label;
    }

    /** @return array{singular: string, plural: string} */
    public static function quantityUnit(Product $product, ProductVariant $variant): array
    {
        if ($variant->unit === QuantityUnit::Piece && str_starts_with($product->id, 'voice-')) {
            return ['singular' => 'piece', 'plural' => 'pieces'];
        }
        if ($variant->unit === QuantityUnit::Count && str_starts_with($product->id, 'voice-')) {
            return ['singular' => 'unit', 'plural' => 'units'];
        }
        if ($variant->unit === QuantityUnit::Portion) {
            return $product->categoryId === 'beverage'
                ? ['singular' => 'serving', 'plural' => 'servings']
                : ['singular' => 'Plate', 'plural' => 'Plates'];
        }
        return ['singular' => 'pack', 'plural' => 'packs'];
    }

    public static function startingPrice(Product $product): string
    {
        $variants = $product->variants();
        $first = $variants[0];
        if (str_starts_with($product->id, 'voice-')) {
            if ($first->unit === QuantityUnit::Gram) {
                return \Kios\Domain\Money::fromPaise(intdiv($first->unitPrice->paise() * 1000, $first->quantityValue))->formatRupees() . ' / kg';
            }
            return $first->unitPrice->formatRupees() . ($first->unit === QuantityUnit::Piece ? ' / piece' : ' / unit');
        }
        $price = $first->unitPrice->formatRupees();
        if (count($variants) > 1) {
            return 'From ' . $price;
        }
        return $price . ' / ' . self::option($product, $first);
    }

    public static function cartQuantity(Product $product, ProductVariant $variant, int $quantity): string
    {
        return self::option($product, $variant) . ' × ' . $quantity;
    }
}
