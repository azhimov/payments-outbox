<?php

declare(strict_types=1);

namespace App\Application\Refunds;

use App\Domain\Payment\Payment;

final readonly class RefundView
{
    public function __construct(
        public string $id,
        public string $status,
        public int $amount,
        public Payment $payment,
    ) {}
}
