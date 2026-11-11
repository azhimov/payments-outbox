<?php

declare(strict_types=1);

namespace App\Application\Refunds;

final readonly class RefundPaymentCommand
{
    public function __construct(
        public string $merchantId,
        public string $paymentId,
        /** Stable across retries of one request (derived from its Idempotency-Key). */
        public string $refundId,
        public int $amount,
    ) {}
}
