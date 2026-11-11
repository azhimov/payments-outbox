<?php

declare(strict_types=1);

namespace App\Application\Refunds;

use App\Application\Ports\ChargeOutcome;
use App\Application\Ports\IntegrationEvent;
use App\Application\Ports\IntegrationEvents;
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
 * 3. tx2: apply it to the payment, mark the refund, publish payment.refunded (outbox).
 * An unclear PSP answer is a 503: the client retries with the same key, gets the same
 * refund id, and the PSP does not refund twice.
 */
final readonly class RefundPayment
{
    public function __construct(
        private PaymentRepository $payments,
        private RefundRepository $refunds,
        private PaymentGateway $gateway,
        private IntegrationEvents $events,
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
            throw new PspUnavailable('The PSP did not answer the refund clearly.');
        }

        return $this->transaction->run(function () use ($command, $amount, $result): RefundView {
            $payment = $this->payments->findForUpdate($command->paymentId) ?? throw new PaymentNotFound;

            if ($this->refunds->status($command->refundId) !== 'processing') {
                return new RefundView($command->refundId, (string) $this->refunds->status($command->refundId), $command->amount, $payment);
            }

            if ($result->outcome !== ChargeOutcome::Succeeded) {
                $this->refunds->markFailed($command->refundId);

                return new RefundView($command->refundId, 'failed', $command->amount, $payment);
            }

            $refund = $payment->refund($command->refundId, $amount);
            $this->payments->save($payment);
            $this->refunds->markSucceeded($command->refundId);

            $this->events->publish(new IntegrationEvent(
                topic: 'payments',
                type: 'payment.refunded',
                payload: [
                    'refund_id' => $refund->id,
                    'payment_id' => $payment->id,
                    'order_id' => $payment->orderId,
                    'merchant_id' => $payment->merchantId,
                    'amount' => $refund->amount->amount,
                    'currency' => $refund->amount->currency->code,
                ],
                // Same aggregate as payment.succeeded: consumers get them in order.
                aggregateId: $payment->orderId,
            ));

            return new RefundView($refund->id, 'succeeded', $refund->amount->amount, $payment);
        });
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
