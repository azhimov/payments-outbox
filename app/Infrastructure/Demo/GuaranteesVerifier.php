<?php

declare(strict_types=1);

namespace App\Infrastructure\Demo;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * The reconciliation behind `make chaos`. Checks the guarantees against three sources
 * of truth: our payments, the PSP's charges (fake_psp_charges) and the ledger.
 */
final class GuaranteesVerifier
{
    /** Payment statuses in which the customer's money was taken. */
    private const CAPTURED = ['succeeded', 'partially_refunded', 'refunded'];

    // fake_psp_charges.payment_id is a string (the PSP knows nothing of our uuid type):
    // the joins cast payments.id, which PostgreSQL requires.

    /**
     * @return list<VerifyCheck>
     */
    public function checks(): array
    {
        $capturedPayments = DB::table('payments')->whereIn('status', self::CAPTURED);
        $paidAmount = (int) (clone $capturedPayments)->sum('amount');
        $refundedAmount = (int) DB::table('refunds')->where('status', 'succeeded')->sum('amount');

        $total = DB::table('ledger_entries')
            ->where('account', 'merchant_payable')
            ->selectRaw("coalesce(sum(case when direction = 'credit' then amount else -amount end), 0) as total")
            ->value('total');
        $merchantPayable = is_numeric($total) ? (int) $total : 0;

        return [
            // Double charge on our side: one order, two captured payments.
            $this->zero('orders with more than one captured payment', $this->rows(DB::table('payments')
                ->whereIn('status', self::CAPTURED)->select('order_id')->groupBy('order_id')->havingRaw('count(*) > 1'))),

            // Double charge at the PSP: one order, two successful charges.
            $this->zero('orders charged more than once at the PSP', $this->rows(DB::table('fake_psp_charges')
                ->join('payments', fn (JoinClause $join) => $join->on(DB::raw('cast(payments.id as varchar(64))'), '=', 'fake_psp_charges.payment_id'))
                ->where('fake_psp_charges.status', 'succeeded')
                ->select('payments.order_id')->groupBy('payments.order_id')->havingRaw('count(*) > 1'))),

            // Money taken that we do not know about: charged at the PSP, not captured here.
            $this->zero('PSP charges not captured in payments', DB::table('fake_psp_charges')
                ->join('payments', fn (JoinClause $join) => $join->on(DB::raw('cast(payments.id as varchar(64))'), '=', 'fake_psp_charges.payment_id'))
                ->where('fake_psp_charges.status', 'succeeded')
                ->whereNotIn('payments.status', self::CAPTURED)
                ->count()),

            $this->zero('payments still processing', DB::table('payments')->where('status', 'processing')->count()),

            $this->zero('refunds still processing', DB::table('refunds')->where('status', 'processing')->count()),

            $this->zero('outbox messages not delivered', DB::table('outbox_messages')->where('status', '!=', 'published')->count()),

            // Lost events: every capture and every refund booked exactly once.
            $this->equal('captured payments booked in the ledger', (clone $capturedPayments)->count(),
                (int) DB::table('ledger_entries')->where('event_type', 'payment.succeeded')->distinct()->count('transaction_id')),

            $this->equal('refunds booked in the ledger', DB::table('refunds')->where('status', 'succeeded')->count(),
                (int) DB::table('ledger_entries')->where('event_type', 'payment.refunded')->distinct()->count('transaction_id')),

            $this->zero('unbalanced ledger transactions', $this->rows(DB::table('ledger_entries')
                ->select('transaction_id')->groupBy('transaction_id')
                ->havingRaw("sum(case when direction = 'debit' then amount else -amount end) <> 0"))),

            $this->equal('merchant payable = paid - refunded (minor units)', $paidAmount - $refundedAmount, $merchantPayable),
        ];
    }

    /**
     * @return array<string, int>
     */
    public function summary(): array
    {
        return [
            'orders' => DB::table('orders')->count(),
            'orders_paid' => DB::table('orders')->where('status', 'paid')->count(),
            'payments' => DB::table('payments')->count(),
            'payments_declined_or_failed' => DB::table('payments')->whereIn('status', ['declined', 'failed'])->count(),
            'psp_charges' => DB::table('fake_psp_charges')->count(),
            'refunds' => DB::table('refunds')->where('status', 'succeeded')->count(),
            'ledger_transactions' => (int) DB::table('ledger_entries')->distinct()->count('transaction_id'),
            'psp_webhooks_processed' => DB::table('inbox_messages')->where('consumer', 'psp-webhooks')->count(),
            'ledger_events_processed' => DB::table('inbox_messages')->where('consumer', 'ledger')->count(),
        ];
    }

    /** Rows of a grouped query, counted in the database. */
    private function rows(Builder $grouped): int
    {
        return DB::query()->fromSub($grouped, 'grouped')->count();
    }

    private function zero(string $name, int $value): VerifyCheck
    {
        return new VerifyCheck($name, $value === 0, $value);
    }

    private function equal(string $name, int $expected, int $actual): VerifyCheck
    {
        return new VerifyCheck($name, $expected === $actual, $expected === $actual ? $actual : "{$actual}, expected {$expected}");
    }
}
