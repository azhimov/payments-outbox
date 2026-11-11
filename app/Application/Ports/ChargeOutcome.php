<?php

declare(strict_types=1);

namespace App\Application\Ports;

enum ChargeOutcome: string
{
    case Succeeded = 'succeeded';
    case Declined = 'declined';
    /** Accepted; the final status comes later (webhook or status query). */
    case Pending = 'pending';
    /** The PSP has no charge for this payment: nothing was taken. */
    case NotFound = 'not_found';
    /** Timeout, 5xx, broken connection: the money may or may not have been taken. */
    case Unknown = 'unknown';
}
