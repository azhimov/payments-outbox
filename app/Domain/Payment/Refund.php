<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Domain\Shared\Money;

final readonly class Refund
{
    public function __construct(
        public string $id,
        public string $paymentId,
        public Money $amount,
    ) {}
}
