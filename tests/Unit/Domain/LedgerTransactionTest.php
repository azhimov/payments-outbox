<?php

declare(strict_types=1);

use App\Domain\Ledger\Direction;
use App\Domain\Ledger\Exceptions\UnbalancedTransaction;
use App\Domain\Ledger\LedgerAccount;
use App\Domain\Ledger\LedgerEntry;
use App\Domain\Ledger\LedgerTransaction;
use App\Domain\Shared\Currency;
use App\Domain\Shared\Money;

function kzt(int $amount): Money
{
    return Money::of($amount, Currency::KZT());
}

it('books a captured payment: PSP owes us, we owe the merchant', function (): void {
    $tx = LedgerTransaction::paymentCaptured('tx-1', kzt(500_000));

    expect(array_map(fn (LedgerEntry $e): array => [$e->account, $e->direction, $e->amount->amount], $tx->entries))->toBe([
        [LedgerAccount::PSP_CLEARING, Direction::Debit, 500_000],
        [LedgerAccount::MERCHANT_PAYABLE, Direction::Credit, 500_000],
    ]);
});

it('books a refund as the reverse entries', function (): void {
    $tx = LedgerTransaction::paymentRefunded('tx-2', kzt(100_000));

    expect(array_map(fn (LedgerEntry $e): array => [$e->account, $e->direction, $e->amount->amount], $tx->entries))->toBe([
        [LedgerAccount::MERCHANT_PAYABLE, Direction::Debit, 100_000],
        [LedgerAccount::PSP_CLEARING, Direction::Credit, 100_000],
    ]);
});

it('always sums to zero (signed)', function (): void {
    expect(LedgerTransaction::paymentCaptured('tx-1', kzt(123))->signedTotal())->toBe(0)
        ->and(LedgerTransaction::paymentRefunded('tx-2', kzt(45))->signedTotal())->toBe(0);
});

it('refuses unbalanced, one-sided or empty transactions', function (array $entries): void {
    new LedgerTransaction('tx-x', $entries);
})->throws(UnbalancedTransaction::class)->with([
    'unbalanced' => [[new LedgerEntry(LedgerAccount::PSP_CLEARING, Direction::Debit, kzt(100)), new LedgerEntry(LedgerAccount::MERCHANT_PAYABLE, Direction::Credit, kzt(99))]],
    'one entry' => [[new LedgerEntry(LedgerAccount::PSP_CLEARING, Direction::Debit, kzt(100))]],
    'nothing' => [[]],
    'zero amounts' => [[new LedgerEntry(LedgerAccount::PSP_CLEARING, Direction::Debit, kzt(0)), new LedgerEntry(LedgerAccount::MERCHANT_PAYABLE, Direction::Credit, kzt(0))]],
]);

it('refuses an unknown account', function (): void {
    new LedgerEntry('petty_cash', Direction::Debit, kzt(1));
})->throws(InvalidArgumentException::class);
