<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Domain\Ledger\Exceptions\UnbalancedTransaction;
use App\Domain\Shared\Money;

/**
 * Double entry: every transaction has debits and credits of equal total, so the signed
 * sum per transaction is 0. Corrections are new (reverse) transactions, never edits.
 */
final readonly class LedgerTransaction
{
    /**
     * @param  list<LedgerEntry>  $entries
     */
    public function __construct(
        public string $id,
        public array $entries,
    ) {
        if (count($entries) < 2) {
            throw new UnbalancedTransaction("Ledger transaction {$id} needs at least a debit and a credit.");
        }

        $currency = $entries[0]->amount->currency;
        foreach ($entries as $entry) {
            if ($entry->amount->isZero() || ! $entry->amount->currency->equals($currency)) {
                throw new UnbalancedTransaction("Ledger transaction {$id} has a zero or mixed-currency entry.");
            }
        }

        if ($this->signedTotal() !== 0) {
            throw new UnbalancedTransaction("Ledger transaction {$id} does not balance.");
        }
    }

    public static function paymentCaptured(string $id, Money $amount): self
    {
        return new self($id, [
            new LedgerEntry(LedgerAccount::PSP_CLEARING, Direction::Debit, $amount),
            new LedgerEntry(LedgerAccount::MERCHANT_PAYABLE, Direction::Credit, $amount),
        ]);
    }

    public static function paymentRefunded(string $id, Money $amount): self
    {
        return new self($id, [
            new LedgerEntry(LedgerAccount::MERCHANT_PAYABLE, Direction::Debit, $amount),
            new LedgerEntry(LedgerAccount::PSP_CLEARING, Direction::Credit, $amount),
        ]);
    }

    public function signedTotal(): int
    {
        return array_sum(array_map(fn (LedgerEntry $e): int => $e->direction->sign() * $e->amount->amount, $this->entries));
    }
}
