<?php

declare(strict_types=1);

namespace App\Application\Ports;

use App\Domain\Ledger\LedgerTransaction;

interface LedgerRepository
{
    public function record(LedgerTransaction $transaction, string $eventType, string $paymentId): void;
}
