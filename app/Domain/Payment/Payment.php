<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Domain\Payment\Exceptions\InvalidPaymentTransition;
use App\Domain\Payment\Exceptions\RefundNotAllowed;
use App\Domain\Shared\Money;
use InvalidArgumentException;

/**
 * created -> processing -> succeeded | declined | failed
 * succeeded -> partially_refunded | refunded, partially_refunded -> partially_refunded | refunded
 */
final class Payment
{
    private function __construct(
        public readonly string $id,
        public readonly string $orderId,
        public readonly string $merchantId,
        public readonly Money $amount,
        private PaymentStatus $status,
        private Money $refunded,
        private ?string $pspPaymentId,
        private ?string $failureReason,
    ) {}

    public static function create(string $id, string $orderId, string $merchantId, Money $amount): self
    {
        if ($amount->isZero()) {
            throw new InvalidArgumentException('A payment needs an amount above zero.');
        }

        return new self($id, $orderId, $merchantId, $amount, PaymentStatus::Created, Money::zero($amount->currency), null, null);
    }

    public static function restore(
        string $id,
        string $orderId,
        string $merchantId,
        Money $amount,
        PaymentStatus $status,
        Money $refunded,
        ?string $pspPaymentId,
        ?string $failureReason,
    ): self {
        return new self($id, $orderId, $merchantId, $amount, $status, $refunded, $pspPaymentId, $failureReason);
    }

    public function startProcessing(): void
    {
        $this->moveTo(PaymentStatus::Processing);
    }

    public function succeed(string $pspPaymentId): void
    {
        $this->moveTo(PaymentStatus::Succeeded);
        $this->pspPaymentId = $pspPaymentId;
    }

    public function decline(string $reason): void
    {
        $this->moveTo(PaymentStatus::Declined);
        $this->failureReason = $reason;
    }

    public function fail(string $reason): void
    {
        $this->moveTo(PaymentStatus::Failed);
        $this->failureReason = $reason;
    }

    public function refund(string $refundId, Money $amount): Refund
    {
        if (! $this->status->canTransitionTo(PaymentStatus::Refunded)) {
            throw InvalidPaymentTransition::between($this->id, $this->status, PaymentStatus::Refunded);
        }

        if ($amount->isZero()) {
            throw new RefundNotAllowed('A refund needs an amount above zero.');
        }

        if ($amount->greaterThan($this->refundable())) {
            throw new RefundNotAllowed(sprintf('Refund of %s exceeds the refundable %s of payment %s.', $amount, $this->refundable(), $this->id));
        }

        $this->refunded = $this->refunded->add($amount);
        $this->moveTo($this->refundable()->isZero() ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded);

        return new Refund($refundId, $this->id, $amount);
    }

    public function status(): PaymentStatus
    {
        return $this->status;
    }

    public function refunded(): Money
    {
        return $this->refunded;
    }

    public function refundable(): Money
    {
        return $this->amount->subtract($this->refunded);
    }

    public function pspPaymentId(): ?string
    {
        return $this->pspPaymentId;
    }

    public function failureReason(): ?string
    {
        return $this->failureReason;
    }

    private function moveTo(PaymentStatus $next): void
    {
        if (! $this->status->canTransitionTo($next)) {
            throw InvalidPaymentTransition::between($this->id, $this->status, $next);
        }

        $this->status = $next;
    }
}
