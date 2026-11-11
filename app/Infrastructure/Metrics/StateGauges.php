<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Gauges read from the database at scrape time: they describe state, not events.
 */
final readonly class StateGauges
{
    public function __construct(private Metrics $metrics) {}

    public function refresh(): void
    {
        $now = Date::now()->getTimestampMs();

        foreach (['pending', 'processing', 'published', 'failed'] as $status) {
            $this->metrics->gauge('outbox_messages', 'Outbox messages by status', (float) DB::table('outbox_messages')->where('status', $status)->count(), ['status'], [$status]);
        }

        $oldest = DB::table('outbox_messages')->whereIn('status', ['pending', 'processing'])->min('created_at');
        $this->metrics->gauge('outbox_lag_seconds', 'Age of the oldest undelivered outbox message', is_numeric($oldest) ? max(0, ($now - (int) $oldest) / 1000) : 0.0);

        $byStatus = DB::table('payments')->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status');
        foreach ($byStatus as $status => $total) {
            $this->metrics->gauge('payments', 'Payments by status', is_numeric($total) ? (float) $total : 0.0, ['status'], [(string) $status]);
        }

        $balances = DB::table('ledger_entries')
            ->select('account', DB::raw("sum(case when direction = 'debit' then amount else -amount end) as balance"))
            ->groupBy('account')
            ->pluck('balance', 'account');
        foreach ($balances as $account => $balance) {
            // Minor units. merchant_payable is a credit balance, so it shows as negative.
            $this->metrics->gauge('ledger_balance', 'Signed ledger balance per account (debit +, credit -), minor units', is_numeric($balance) ? (float) $balance : 0.0, ['account'], [(string) $account]);
        }
    }
}
