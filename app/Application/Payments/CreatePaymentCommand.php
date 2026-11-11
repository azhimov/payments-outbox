<?php

declare(strict_types=1);

namespace App\Application\Payments;

final readonly class CreatePaymentCommand
{
    public function __construct(
        public string $merchantId,
        public string $orderId,
        public string $cardToken,
    ) {}
}
