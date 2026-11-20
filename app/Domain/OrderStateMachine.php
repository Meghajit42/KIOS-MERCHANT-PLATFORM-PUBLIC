<?php
declare(strict_types=1);

namespace Kios\Domain;

use DomainException;

final class OrderStateMachine
{
    public static function canTransition(OrderStatus $from, OrderStatus $to): bool
    {
        $allowed = match ($from) {
            OrderStatus::Draft => [OrderStatus::AwaitingPayment, OrderStatus::Cancelled, OrderStatus::Expired],
            OrderStatus::AwaitingPayment => [OrderStatus::Paid, OrderStatus::Cancelled, OrderStatus::Expired],
            OrderStatus::Paid => [OrderStatus::Preparing, OrderStatus::Cancelled],
            OrderStatus::Preparing => [OrderStatus::Ready, OrderStatus::Cancelled],
            OrderStatus::Ready => [OrderStatus::Collected],
            OrderStatus::Collected, OrderStatus::Cancelled, OrderStatus::Expired => [],
        };
        return in_array($to, $allowed, true);
    }

    public static function transition(OrderStatus $from, OrderStatus $to): OrderStatus
    {
        if (!self::canTransition($from, $to)) {
            throw new DomainException("Invalid order transition: {$from->value} to {$to->value}.");
        }
        return $to;
    }
}
