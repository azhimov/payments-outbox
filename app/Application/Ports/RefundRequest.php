<?php

declare(strict_types=1);

namespace App\Application\Ports;

use App\Domain\Shared\Money;

final readonly class RefundRequest
{
    public function __construct(
        /** Also the idempotency key at the PSP. */
        public string $refundId,
        public string $paymentId,
        public Money $amount,
    ) {}
}
