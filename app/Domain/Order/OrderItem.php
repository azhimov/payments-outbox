<?php

declare(strict_types=1);

namespace App\Domain\Order;

use App\Domain\Order\Exceptions\InvalidOrder;
use App\Domain\Shared\Money;

final readonly class OrderItem
{
    public function __construct(
        public string $sku,
        public string $name,
        public int $quantity,
        public Money $unitPrice,
    ) {
        if (trim($sku) === '' || trim($name) === '') {
            throw new InvalidOrder('An order item needs a SKU and a name.');
        }

        if ($quantity < 1) {
            throw new InvalidOrder("Quantity of {$sku} must be at least 1.");
        }
    }

    public function total(): Money
    {
        return $this->unitPrice->times($this->quantity);
    }
}
