<?php

declare(strict_types=1);

namespace App\Application\Ports;

interface PaymentGateway
{
    /** Never throws for PSP trouble: an unclear answer is ChargeOutcome::Unknown. */
    public function charge(ChargeRequest $request): ChargeResult;

    /** What the PSP knows about our payment id (used by reconcile). */
    public function status(string $paymentId): ChargeResult;
}
