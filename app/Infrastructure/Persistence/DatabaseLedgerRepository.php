<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Ports\LedgerRepository;
use App\Domain\Ledger\LedgerEntry;
use App\Domain\Ledger\LedgerTransaction;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

final class DatabaseLedgerRepository implements LedgerRepository
{
    public function record(LedgerTransaction $transaction, string $eventType, string $paymentId): void
    {
        $now = Date::now();

        DB::table('ledger_entries')->insert(array_map(fn (LedgerEntry $entry): array => [
            'transaction_id' => $transaction->id,
            'account' => $entry->account,
            'direction' => $entry->direction->value,
            'amount' => $entry->amount->amount,
            'currency' => $entry->amount->currency->code,
            'event_type' => $eventType,
            'payment_id' => $paymentId,
            'created_at' => $now,
        ], $transaction->entries));
    }
}
