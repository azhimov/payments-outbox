<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

final class LedgerAccount
{
    /** What the PSP owes us for captured payments, until it settles. */
    public const PSP_CLEARING = 'psp_clearing';

    /** What we owe the merchant for their paid orders. */
    public const MERCHANT_PAYABLE = 'merchant_payable';

    /** @var list<string> */
    public const ALL = [self::PSP_CLEARING, self::MERCHANT_PAYABLE];
}
