<?php

declare(strict_types=1);

namespace App\Application\Ports;

use App\Domain\Shared\Money;

interface RefundRepository
{
    /** @return 'processing'|'succeeded'|'failed'|null */
    public function status(string $refundId): ?string;

    public function amount(string $refundId): ?int;

    /** Sum of refunds still processing (reserved) for a payment. */
    public function reserved(string $paymentId): int;

    public function createProcessing(string $refundId, string $paymentId, string $merchantId, Money $amount): void;

    public function markSucceeded(string $refundId): void;

    public function markFailed(string $refundId): void;
}
