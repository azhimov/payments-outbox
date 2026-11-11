<?php

declare(strict_types=1);

namespace App\Domain\Order;

use App\Domain\Order\Exceptions\InvalidOrder;
use App\Domain\Shared\Money;

final class Order
{
    /**
     * @param  non-empty-list<OrderItem>  $items
     */
    private function __construct(
        public readonly string $id,
        public readonly string $merchantId,
        private readonly array $items,
        private OrderStatus $status,
    ) {}

    /**
     * @param  list<OrderItem>  $items
     */
    public static function place(string $id, string $merchantId, array $items): self
    {
        if ($items === []) {
            throw new InvalidOrder('An order needs at least one item.');
        }

        $currency = $items[0]->unitPrice->currency;
        foreach ($items as $item) {
            if (! $item->unitPrice->currency->equals($currency)) {
                throw new InvalidOrder('All items of an order must use one currency.');
            }
        }

        return new self($id, $merchantId, $items, OrderStatus::Pending);
    }

    /**
     * @param  non-empty-list<OrderItem>  $items
     */
    public static function restore(string $id, string $merchantId, array $items, OrderStatus $status): self
    {
        return new self($id, $merchantId, $items, $status);
    }

    public function markPaid(): void
    {
        if ($this->status !== OrderStatus::Pending) {
            throw new InvalidOrder("Order {$this->id} is already {$this->status->value}.");
        }

        $this->status = OrderStatus::Paid;
    }

    public function total(): Money
    {
        $total = Money::zero($this->items[0]->unitPrice->currency);

        foreach ($this->items as $item) {
            $total = $total->add($item->total());
        }

        return $total;
    }

    public function status(): OrderStatus
    {
        return $this->status;
    }

    /**
     * @return non-empty-list<OrderItem>
     */
    public function items(): array
    {
        return $this->items;
    }
}
