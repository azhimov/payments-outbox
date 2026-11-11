<?php

declare(strict_types=1);

namespace App\Domain\Payment;

enum PaymentStatus: string
{
    case Created = 'created';
    /** Sent to the PSP; the outcome may still be unknown (timeout, 5xx). */
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Declined = 'declined';
    case Failed = 'failed';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Created => [self::Processing],
            self::Processing => [self::Succeeded, self::Declined, self::Failed],
            self::Succeeded, self::PartiallyRefunded => [self::PartiallyRefunded, self::Refunded],
            self::Declined, self::Failed, self::Refunded => [],
        }, true);
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Declined, self::Failed, self::Refunded], true);
    }
}
