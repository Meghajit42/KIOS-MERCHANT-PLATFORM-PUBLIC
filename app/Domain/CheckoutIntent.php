<?php
declare(strict_types=1);

namespace Kios\Domain;

use InvalidArgumentException;
use JsonException;

final class CheckoutIntent
{
    public static function generateKey(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function isValidKey(string $key): bool
    {
        return preg_match('/^[a-f0-9]{64}$/D', $key) === 1;
    }

    /** @param array<string, array<string, int>> $cart */
    public static function fingerprintCart(array $cart, string $outletCode, OrderType $orderType): string
    {
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,79}$/D', $outletCode) !== 1) {
            throw new InvalidArgumentException('The checkout outlet is invalid.');
        }

        $items = [];
        foreach ($cart as $sku => $variants) {
            if (!is_string($sku) || $sku === '' || !is_array($variants) || $variants === []) {
                throw new InvalidArgumentException('The checkout cart is invalid.');
            }
            $normalizedVariants = [];
            foreach ($variants as $variantCode => $quantity) {
                if (!is_string($variantCode) || $variantCode === '' || !is_int($quantity) || $quantity < 1 || $quantity > 99) {
                    throw new InvalidArgumentException('The checkout cart is invalid.');
                }
                $normalizedVariants[$variantCode] = $quantity;
            }
            ksort($normalizedVariants, SORT_STRING);
            $items[$sku] = $normalizedVariants;
        }
        ksort($items, SORT_STRING);

        try {
            $canonical = json_encode(
                ['outlet' => $outletCode, 'orderType' => $orderType->value, 'items' => $items],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException $error) {
            throw new InvalidArgumentException('The checkout cart could not be fingerprinted.', previous: $error);
        }
        return hash('sha256', $canonical);
    }
}
