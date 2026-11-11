<?php

declare(strict_types=1);

namespace App\Application\Ledger;

enum LedgerOutcome: string
{
    case Posted = 'posted';
    /** Delivered again (at-least-once): already booked, nothing written. */
    case Duplicate = 'duplicate';
    /** Not an event the ledger books. */
    case Ignored = 'ignored';
}
