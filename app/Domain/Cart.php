<?php
declare(strict_types=1);

namespace Kios\Domain;

use Closure;
use InvalidArgumentException;

final class Cart
{
    private Closure $productLookup;

    /** @var array<string, array<string, int>> */
    private array $state = [];

    /** @param callable(string): ?Product $productLookup */
    public function __construct(callable $productLookup, array $state = [])
    {
        $this->productLookup = Closure::fromCallable($productLookup);
        foreach ($state as $productId => $variants) {
            if (!is_string($productId) || !is_array($variants)) {
                continue;
            }
            foreach ($variants as $variantId => $quantity) {
                if (is_string($variantId) && is_int($quantity) && $quantity >= 1 && $quantity <= 99) {
                    try {
                        $this->resolve($productId, $variantId, false);
                        $this->state[$productId][$variantId] = $quantity;
                    } catch (InvalidArgumentException) {
                        // Discard stale/unavailable session selections.
                    }
                }
            }
        }
    }

    public function add(string $productId, string $variantId, int $quantity = 1): void
    {
        $this->assertQuantity($quantity);
        $this->resolve($productId, $variantId);
        $next = ($this->state[$productId][$variantId] ?? 0) + $quantity;
        $this->assertQuantity($next);
        $this->state[$productId][$variantId] = $next;
    }

    public function update(string $productId, string $variantId, int $quantity): void
    {
        $this->assertQuantity($quantity);
        $this->resolve($productId, $variantId, false);
        $this->state[$productId][$variantId] = $quantity;
    }

    public function remove(string $productId, string $variantId): void
    {
        unset($this->state[$productId][$variantId]);
        if (($this->state[$productId] ?? []) === []) {
            unset($this->state[$productId]);
        }
    }

    public function clear(): void
    {
        $this->state = [];
    }

    /** @return list<array{product: Product, variant: ProductVariant, quantity: int, unitPrice: Money, lineTotal: Money}> */
    public function lines(): array
    {
        $lines = [];
        foreach ($this->state as $productId => $variants) {
            foreach ($variants as $variantId => $quantity) {
                [$product, $variant] = $this->resolve($productId, $variantId, false);
                $lines[] = [
                    'product' => $product,
                    'variant' => $variant,
                    'quantity' => $quantity,
                    'unitPrice' => $variant->unitPrice,
                    'lineTotal' => $variant->unitPrice->multiply($quantity),
                ];
            }
        }
        return $lines;
    }

    public function total(): Money
    {
        $total = Money::zero();
        foreach ($this->lines() as $line) {
            $total = $total->add($line['lineTotal']);
        }
        return $total;
    }

    public function itemCount(): int
    {
        return array_sum(array_map(static fn(array $variants): int => array_sum($variants), $this->state));
    }

    /** @return array<string, array<string, int>> */
    public function toArray(): array
    {
        return $this->state;
    }

    /** @return array{Product, ProductVariant} */
    private function resolve(string $productId, string $variantId, bool $requireOrderable = true): array
    {
        $product = ($this->productLookup)($productId);
        if (!$product instanceof Product || ($requireOrderable && (!$product->active || !$product->available))) {
            throw new InvalidArgumentException('This product is currently unavailable.');
        }
        $variant = $product->variant($variantId);
        if ($variant === null || ($requireOrderable && !$variant->active)) {
            throw new InvalidArgumentException('The selected product option is invalid.');
        }
        return [$product, $variant];
    }

    private function assertQuantity(int $quantity): void
    {
        if ($quantity < 1 || $quantity > 99) {
            throw new InvalidArgumentException('Quantity must be between 1 and 99.');
        }
    }
}
