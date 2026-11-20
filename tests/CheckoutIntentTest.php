<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use Kios\Domain\CheckoutIntent;
use Kios\Domain\OrderType;

return [
    'checkout intent key is high entropy and validates canonical format' => static function (): void {
        $first = CheckoutIntent::generateKey();
        $second = CheckoutIntent::generateKey();
        if (!CheckoutIntent::isValidKey($first) || !CheckoutIntent::isValidKey($second) || hash_equals($first, $second)
            || CheckoutIntent::isValidKey(strtoupper($first)) || CheckoutIntent::isValidKey(substr($first, 1))) {
            throw new RuntimeException('checkout intent key generation or validation is incorrect');
        }
    },
    'cart fingerprint is canonical and binds outlet, order type, item, variant, and quantity' => static function (): void {
        $first = ['papdi-chaat' => ['papdi-plate' => 1], 'kaju-barfi' => ['kaju-250g' => 2, 'kaju-100g' => 1]];
        $sameReordered = ['kaju-barfi' => ['kaju-100g' => 1, 'kaju-250g' => 2], 'papdi-chaat' => ['papdi-plate' => 1]];
        $fingerprint = CheckoutIntent::fingerprintCart($first, 'demo-outlet', OrderType::Takeaway);
        if (!hash_equals($fingerprint, CheckoutIntent::fingerprintCart($sameReordered, 'demo-outlet', OrderType::Takeaway))
            || hash_equals($fingerprint, CheckoutIntent::fingerprintCart($first, 'other-outlet', OrderType::Takeaway))
            || hash_equals($fingerprint, CheckoutIntent::fingerprintCart($first, 'demo-outlet', OrderType::DineIn))
            || hash_equals($fingerprint, CheckoutIntent::fingerprintCart(['kaju-barfi' => ['kaju-100g' => 2]], 'demo-outlet', OrderType::Takeaway))) {
            throw new RuntimeException('canonical checkout cart fingerprint omitted a bound dimension');
        }
    },
    'cart fingerprint rejects invalid quantities and malformed cart trees' => static function (): void {
        foreach ([['item' => ['unit' => 0]], ['item' => ['unit' => 100]], ['item' => ['unit' => '1']], ['' => ['unit' => 1]]] as $cart) {
            try {
                CheckoutIntent::fingerprintCart($cart, 'demo-outlet', OrderType::Takeaway);
            } catch (InvalidArgumentException) {
                continue;
            }
            throw new RuntimeException('invalid cart was accepted into a checkout fingerprint');
        }
    },
    'customer route persists and submits checkout intent with session and cart binding' => static function (string $root): void {
        $source = file_get_contents($root . '/public/index.php');
        if (!is_string($source)
            || !str_contains($source, 'ensureCheckoutIntent(')
            || !str_contains($source, 'name="checkout_intent_key"')
            || !str_contains($source, 'createFromCheckoutIntent(')
            || !str_contains($source, 'session_id(),')
            || substr_count($source, 'rotateCheckoutIntentAfterCartMutation(') < 4) {
            throw new RuntimeException('customer checkout route does not maintain an intent across cart changes and submission');
        }
    },
];
