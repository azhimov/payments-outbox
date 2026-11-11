<?php

declare(strict_types=1);

use App\Application\Ledger\IncomingEvent;
use App\Application\Ledger\InvalidLedgerEvent;
use App\Application\Ledger\LedgerOutcome;
use App\Application\Ledger\RecordLedgerEntries;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function ledgerEvent(string $type = 'payment.succeeded', array $payload = [], ?string $id = null): IncomingEvent
{
    return new IncomingEvent($id ?? (string) Str::uuid7(), $type, array_merge([
        'payment_id' => (string) Str::uuid7(), 'amount' => 500_000, 'currency' => 'KZT',
    ], $payload));
}

it('books a captured payment as a balanced pair of entries', function (): void {
    $event = ledgerEvent();

    expect(app(RecordLedgerEntries::class)->handle($event))->toBe(LedgerOutcome::Posted);

    $entries = DB::table('ledger_entries')->where('transaction_id', $event->id)->orderBy('id')->get();
    expect($entries->map(fn ($e): array => [$e->account, $e->direction, (int) $e->amount])->all())->toBe([
        ['psp_clearing', 'debit', 500_000],
        ['merchant_payable', 'credit', 500_000],
    ]);
});

it('books a refund as reverse entries', function (): void {
    $event = ledgerEvent('payment.refunded', ['amount' => 100_000]);

    app(RecordLedgerEntries::class)->handle($event);

    expect(DB::table('ledger_entries')->where('transaction_id', $event->id)->where('direction', 'debit')->value('account'))->toBe('merchant_payable');
});

it('books a redelivered event once', function (): void {
    $event = ledgerEvent();
    $record = app(RecordLedgerEntries::class);

    $record->handle($event);
    $again = $record->handle($event);

    expect($again)->toBe(LedgerOutcome::Duplicate)
        ->and(DB::table('ledger_entries')->count())->toBe(2);
});

it('ignores events it does not book', function (): void {
    expect(app(RecordLedgerEntries::class)->handle(ledgerEvent('order.created')))->toBe(LedgerOutcome::Ignored)
        ->and(DB::table('ledger_entries')->count())->toBe(0);
});

it('refuses an event it cannot book, without writing the inbox mark', function (array $payload): void {
    $event = ledgerEvent(payload: $payload);

    expect(fn () => app(RecordLedgerEntries::class)->handle($event))->toThrow(InvalidLedgerEvent::class)
        ->and(DB::table('inbox_messages')->count())->toBe(0);
})->with([
    'float amount' => [['amount' => 10.5]],
    'no currency' => [['currency' => null]],
    'other currency' => [['currency' => 'USD']],
    'no payment' => [['payment_id' => '']],
]);

it('keeps every transaction at zero', function (): void {
    $record = app(RecordLedgerEntries::class);
    $record->handle(ledgerEvent());
    $record->handle(ledgerEvent('payment.refunded', ['amount' => 1]));

    $unbalanced = DB::table('ledger_entries')
        ->selectRaw("transaction_id, sum(case when direction = 'debit' then amount else -amount end) as total")
        ->groupBy('transaction_id')
        ->havingRaw("sum(case when direction = 'debit' then amount else -amount end) <> 0")
        ->count();

    expect($unbalanced)->toBe(0);
});
