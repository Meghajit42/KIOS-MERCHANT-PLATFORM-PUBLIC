<?php
declare(strict_types=1);

namespace Kios\Application;

use Kios\Domain\Order;

final class CollectionQueueView
{
    /** @param list<Order> $orders */
    public static function render(array $orders, string $csrfToken): string
    {
        $escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<section class="cashier-pending-queue" aria-labelledby="collection-queue-title">'
            . '<div class="cashier-section-heading"><div><p class="eyebrow">Ground Floor handoff</p>'
            . '<h2 id="collection-queue-title">Ready for pickup</h2></div>'
            . '<span class="cashier-status status-ready">' . count($orders) . '</span></div>';
        if ($orders === []) {
            return $html . '<article class="cashier-order panel" role="status"><p>No fully prepared orders are waiting for collection.</p></article></section>';
        }
        foreach ($orders as $order) {
            $html .= '<article class="cashier-order panel"><div class="cashier-order-heading"><div>'
                . '<p class="eyebrow">' . $escape(strtoupper(str_replace('_', ' ', $order->type->value))) . '</p>'
                . '<h3>' . $escape($order->publicToken()) . '</h3></div>'
                . '<span class="cashier-status status-ready">READY FOR PICKUP</span></div><ul class="cashier-items">';
            foreach ($order->items as $item) {
                $html .= '<li><span><strong>' . $escape($item->quantity) . ' × ' . $escape($item->name)
                    . '</strong><small>' . $escape($item->variantLabel) . '</small></span><strong>'
                    . $escape($item->lineTotal()->formatRupees()) . '</strong></li>';
            }
            $html .= '</ul><div class="cashier-total"><span>Order total</span><strong>'
                . $escape($order->total->formatRupees()) . '</strong></div>'
                . '<form method="post" action="/cashier.php" class="station-task-action">'
                . '<input type="hidden" name="_csrf" value="' . $escape($csrfToken) . '">'
                . '<input type="hidden" name="action" value="collect-order">'
                . '<input type="hidden" name="token" value="' . $escape($order->publicToken()) . '">'
                . '<button class="button button-paid button-wide" type="submit">Mark collected</button></form></article>';
        }
        return $html . '</section>';
    }
}
