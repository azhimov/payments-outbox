<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

enum Direction: string
{
    case Debit = 'debit';
    case Credit = 'credit';

    /** Debits count positive, credits negative: a balanced transaction sums to 0. */
    public function sign(): int
    {
        return $this === self::Debit ? 1 : -1;
    }
}
