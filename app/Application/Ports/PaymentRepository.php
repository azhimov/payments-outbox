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

    /**
     * Ids of payments still processing and last touched before $before, oldest first.
     *
     * @return list<string>
     */
    public function processingSince(\DateTimeImmutable $before, int $limit): array;
}
