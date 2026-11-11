<?php

declare(strict_types=1);

namespace App\Application\Ports;

use App\Application\Payments\Exceptions\PaymentAlreadyInProgress;
use App\Domain\Payment\Payment;

interface PaymentRepository
{
    /**
     * @throws PaymentAlreadyInProgress when the order already has a live payment
     */
    public function save(Payment $payment): void;

    public function find(string $id, string $merchantId): ?Payment;

    /** Locks the row until the surrounding transaction ends. */
    public function findForUpdate(string $id): ?Payment;
}
