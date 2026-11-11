<?php

declare(strict_types=1);

namespace App\Application\Orders;

final readonly class CreateOrderCommand
{
    /**
     * @param  list<OrderItemData>  $items
     */
    public function __construct(
        public string $merchantId,
        public string $currency,
        public array $items,
    ) {}
}
