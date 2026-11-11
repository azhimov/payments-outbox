<?php

declare(strict_types=1);

namespace App\Application\Orders;

final readonly class OrderItemData
{
    public function __construct(
        public string $sku,
        public string $name,
        public int $quantity,
        public int $unitPrice,
    ) {}
}
