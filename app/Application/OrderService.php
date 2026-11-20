<?php
declare(strict_types=1);

namespace Kios\Application;

use InvalidArgumentException;
use Kios\Domain\Cart;
use Kios\Domain\Money;
use Kios\Domain\Order;
use Kios\Domain\OrderItem;
use Kios\Domain\OrderStateMachine;
use Kios\Domain\OrderStatus;
use Kios\Domain\OrderType;
use Kios\Domain\TokenGenerator;

final class OrderService
{
    public function __construct(private readonly TokenGenerator $tokens = new TokenGenerator())
    {
    }

    /** @param list<array<string, mixed>> $existingOrders */
    public function create(Cart $cart, OrderType $type, array $existingOrders): Order
    {
        $lines = $cart->lines();
        if ($lines === []) {
            throw new InvalidArgumentException('Add an available item before confirming an order.');
        }

        $outletIds = array_values(array_unique(array_map(
            static fn(array $line): string => $line['product']->outletId,
            $lines,
        )));
        if (count($outletIds) !== 1) {
            throw new InvalidArgumentException('An order may contain items from one outlet only.');
        }
        $outletId = $outletIds[0];

        $items = [];
        foreach ($lines as $line) {
            $items[] = new OrderItem(
                $line['product']->id,
                $line['variant']->id,
                $line['product']->name,
                $line['variant']->label,
                $line['variant']->unit,
                $line['quantity'],
                $line['unitPrice'],
            );
        }

        $existingTokens = array_values(array_filter(array_map(
            static fn(array $order): mixed => $order['token'] ?? null,
            $existingOrders,
        ), 'is_string'));
        $status = OrderStateMachine::transition(OrderStatus::Draft, OrderStatus::AwaitingPayment);
        $total = Money::zero();
        foreach ($items as $item) {
            $total = $total->add($item->lineTotal());
        }

        $existingIds = array_values(array_filter(array_map(
            static fn(array $order): mixed => $order['id'] ?? null,
            $existingOrders,
        ), 'is_string'));
        do {
            $id = 'O-' . strtoupper(bin2hex(random_bytes(8)));
        } while (in_array($id, $existingIds, true));

        return new Order(
            $id,
            $outletId,
            $this->tokens->generate($existingTokens),
            $type,
            $status,
            $items,
            $total,
            gmdate(DATE_ATOM),
            [OrderStatus::Draft->value, $status->value],
        );
    }
}
