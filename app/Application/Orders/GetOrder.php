<?php

declare(strict_types=1);

namespace App\Application\Orders;

use App\Application\Ports\OrderRepository;
use App\Domain\Order\Order;

final readonly class GetOrder
{
    public function __construct(private OrderRepository $orders) {}

    public function handle(string $orderId, string $merchantId): ?Order
    {
        return $this->orders->find($orderId, $merchantId);
    }
}
