<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Domain\Shared\Money;
use InvalidArgumentException;

final readonly class LedgerEntry
{
    public function __construct(
        public string $account,
        public Direction $direction,
        public Money $amount,
    ) {
        if (! in_array($account, LedgerAccount::ALL, true)) {
            throw new InvalidArgumentException("Unknown ledger account [{$account}].");
        }
    }
}
