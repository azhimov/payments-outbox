<?php

declare(strict_types=1);

namespace App\Application\Ports;

use App\Domain\Shared\Money;

final readonly class ChargeRequest
{
    public function __construct(
        /** Also the idempotency key at the PSP: retrying a charge never charges twice. */
        public string $paymentId,
        public Money $amount,
        public string $cardToken,
        public ?string $webhookUrl = null,
    ) {}
}
