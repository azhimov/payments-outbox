<?php

declare(strict_types=1);

namespace App\Application\Ports;

use App\Domain\Order\Order;

interface OrderRepository
{
    public function save(Order $order): void;

    /** Scoped to the merchant: another merchant's order is "not found". */
    public function find(string $id, string $merchantId): ?Order;
}
