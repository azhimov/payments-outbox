<?php

declare(strict_types=1);

namespace App\Application\Refunds;

use App\Application\Ports\ChargeOutcome;
use App\Application\Ports\PaymentGateway;
use App\Application\Ports\PaymentRepository;
use App\Application\Ports\RefundRepository;
use App\Application\Ports\RefundRequest;
use App\Application\Ports\TransactionRunner;
use App\Application\Refunds\Exceptions\PaymentNotFound;
use App\Application\Refunds\Exceptions\PspUnavailable;
use App\Domain\Payment\Exceptions\InvalidPaymentTransition;
use App\Domain\Payment\Exceptions\RefundNotAllowed;
use App\Domain\Payment\Payment;
use App\Domain\Payment\PaymentStatus;
use App\Domain\Shared\Money;

/**
 * 1. tx1: lock the payment, check the amount against what is left minus refunds already
 *    in flight, reserve it as a processing refund (or reuse the row of a retried request);
 * 2. refund at the PSP, outside a transaction, keyed by the refund id;
 * 3. tx2: RefundOutcomes applies it (payment, refund, payment.refunded in one transaction).
 * An unclear PSP answer is a 503: a retry with the same key gets the same refund id, so the
 * PSP refunds once; refunds:reconcile finishes refunds nobody retries.
 */
final readonly class RefundPayment
{
    public function __construct(
        private PaymentRepository $payments,
        private RefundRepository $refunds,
        private PaymentGateway $gateway,
        private RefundOutcomes $outcomes,
        private TransactionRunner $transaction,
    ) {}

    public function handle(RefundPaymentCommand $command): RefundView
    {
        $payment = $this->reserve($command);
        $existing = $this->refunds->status($command->refundId);

        if ($existing !== 'processing') {
            return new RefundView($command->refundId, (string) $existing, $this->refunds->amount($command->refundId) ?? $command->amount, $payment);
        }

        $amount = Money::of($command->amount, $payment->amount->currency);
        $result = $this->gateway->refund(new RefundRequest($command->refundId, $payment->id, $amount));

        if ($result->outcome === ChargeOutcome::Unknown) {
            // Not stored by laravel-idempotency (5xx): a retry with the same key runs again,
            // and refunds:reconcile finishes it if the client never comes back.
            throw new PspUnavailable('The PSP did not answer the refund clearly.');
        }

        return $this->outcomes->apply($command->refundId, $result);
    }

    private function reserve(RefundPaymentCommand $command): Payment
    {
        return $this->transaction->run(function () use ($command): Payment {
            $payment = $this->payments->findForUpdate($command->paymentId);

            if ($payment === null || $payment->merchantId !== $command->merchantId) {
                throw new PaymentNotFound;
            }

            if ($this->refunds->status($command->refundId) !== null) {
                return $payment;
            }

            if (! $payment->status()->canTransitionTo(PaymentStatus::Refunded)) {
                throw InvalidPaymentTransition::between($payment->id, $payment->status(), PaymentStatus::Refunded);
            }

            $amount = Money::of(max(0, $command->amount), $payment->amount->currency);
            $available = $payment->refundable()->amount - $this->refunds->reserved($payment->id);

            if ($amount->isZero() || $amount->amount > $available) {
                throw new RefundNotAllowed("Refund of {$amount->amount} exceeds the {$available} still refundable.");
            }

            $this->refunds->createProcessing($command->refundId, $payment->id, $payment->merchantId, $amount);

            return $payment;
        });
    }
}
