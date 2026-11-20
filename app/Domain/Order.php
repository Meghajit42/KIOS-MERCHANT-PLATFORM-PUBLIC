<?php
declare(strict_types=1);

namespace Kios\Domain;

use InvalidArgumentException;

final readonly class Order
{
    /** @param list<OrderItem> $items @param list<string> $statusHistory */
    public function __construct(
        public string $id,
        public string $outletId,
        public string $token,
        public OrderType $type,
        public OrderStatus $status,
        public array $items,
        public Money $total,
        public string $createdAt,
        public array $statusHistory,
        public ?string $pickupToken = null,
    ) {
        if (trim($id) === '' || trim($outletId) === '' || !preg_match('/^G-[A-F0-9]{16}$/', $token) || $items === []) {
            throw new InvalidArgumentException('Order identity, outlet, token and at least one item are required.');
        }
        if ($pickupToken !== null && !preg_match('/^G-[0-9]{3,}$/D', $pickupToken)) {
            throw new InvalidArgumentException('A public pickup token must use the G-NNN format.');
        }
    }

    public function publicToken(): string
    {
        return $this->pickupToken ?? $this->token;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'outletId' => $this->outletId,
            'token' => $this->token,
            'pickupToken' => $this->pickupToken,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'totalPaise' => $this->total->paise(),
            'createdAt' => $this->createdAt,
            'statusHistory' => $this->statusHistory,
            'items' => array_map(static fn(OrderItem $item): array => [
                'productId' => $item->productId,
                'variantId' => $item->variantId,
                'name' => $item->name,
                'variantLabel' => $item->variantLabel,
                'unit' => $item->unit->value,
                'quantity' => $item->quantity,
                'unitPricePaise' => $item->unitPrice->paise(),
                'lineTotalPaise' => $item->lineTotal()->paise(),
            ], $this->items),
        ];
    }
}
