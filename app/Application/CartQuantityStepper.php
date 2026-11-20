<?php
declare(strict_types=1);

namespace Kios\Application;

use DomainException;
use InvalidArgumentException;
use Kios\Domain\Cart;
use Kios\Domain\Product;
use Kios\Domain\ProductVariant;
use Kios\Domain\QuantityUnit;

final class CartQuantityStepper
{
    public function step(Cart $cart, Product $product, ProductVariant $variant, int $delta, bool $orderable): int
    {
        if (!in_array($delta, [-1, 1], true) || $variant->unit === QuantityUnit::Gram
            || $product->variant($variant->id) !== $variant) {
            throw new InvalidArgumentException('This quantity cannot be changed here.');
        }
        $current = $cart->toArray()[$product->id][$variant->id] ?? 0;
        if ($delta === 1) {
            if (!$orderable) {
                throw new DomainException('This item is no longer available. Please remove it from your order.');
            }
            $cart->add($product->id, $variant->id);
        } elseif ($current > 1) {
            $cart->update($product->id, $variant->id, $current - 1);
        } elseif ($current === 1) {
            $cart->remove($product->id, $variant->id);
        }
        return $cart->toArray()[$product->id][$variant->id] ?? 0;
    }
}
