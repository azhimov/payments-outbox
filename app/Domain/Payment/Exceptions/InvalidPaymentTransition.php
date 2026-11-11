<?php

declare(strict_types=1);

namespace App\Domain\Payment\Exceptions;

use App\Domain\Payment\PaymentStatus;
use DomainException;

final class InvalidPaymentTransition extends DomainException
{
    public static function between(string $paymentId, PaymentStatus $from, PaymentStatus $to): self
    {
        return new self("Payment {$paymentId} cannot go from {$from->value} to {$to->value}.");
    }
}
