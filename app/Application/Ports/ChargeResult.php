<?php

declare(strict_types=1);

namespace App\Application\Ports;

final readonly class ChargeResult
{
    public function __construct(
        public ChargeOutcome $outcome,
        public ?string $pspPaymentId = null,
        public ?string $reason = null,
    ) {}
}
