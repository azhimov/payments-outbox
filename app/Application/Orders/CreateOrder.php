<?php

declare(strict_types=1);

namespace App\Application\Orders;

use App\Application\Ports\IdGenerator;
use App\Application\Ports\OrderRepository;
use App\Domain\Order\Order;
use App\Domain\Order\OrderItem;
use App\Domain\Shared\Currency;
use App\Domain\Shared\Money;

final readonly class CreateOrder
{
    public function __construct(
        private OrderRepository $orders,
        private IdGenerator $ids,
    ) {}

    public function handle(CreateOrderCommand $command): Order
    {
        $currency = Currency::from($command->currency);

        $order = Order::place($this->ids->next(), $command->merchantId, array_map(
            fn (OrderItemData $item): OrderItem => new OrderItem($item->sku, $item->name, $item->quantity, Money::of($item->unitPrice, $currency)),
            $command->items,
        ));

        $this->orders->save($order);

        return $order;
    }
}
