<?php

declare(strict_types=1);

namespace App\Application\Refunds;

use App\Application\Ports\ChargeOutcome;
use App\Application\Ports\ChargeResult;
use App\Application\Ports\IntegrationEvent;
use App\Application\Ports\IntegrationEvents;
use App\Application\Ports\PaymentRepository;
use App\Application\Ports\RefundRepository;
use App\Application\Ports\TransactionRunner;
use App\Application\Refunds\Exceptions\PaymentNotFound;
use App\Domain\Shared\Money;
use LogicException;

/**
 * Applies what the PSP said about a refund. The one place that finalizes refunds: the
 * client's request (and its retries) and refunds:reconcile both come through here.
 *
 * Idempotent: the payment row is locked and a refund that is no longer processing is left
 * alone, so whoever comes second is a no-op. On success the payment, the refund and
 * payment.refunded commit together; a refund the PSP never made releases its reservation.
 */
final readonly class RefundOutcomes
{
    public function __construct(
        private PaymentRepository $payments,
        private RefundRepository $refunds,
        private IntegrationEvents $events,
        private TransactionRunner $transaction,
    ) {}

    public function apply(string $refundId, ChargeResult $result): RefundView
    {
        return $this->transaction->run(function () use ($refundId, $result): RefundView {
            $paymentId = $this->refunds->paymentId($refundId) ?? throw new LogicException("Refund {$refundId} does not exist.");
            $payment = $this->payments->findForUpdate($paymentId) ?? throw new PaymentNotFound;
            $amount = $this->refunds->amount($refundId) ?? throw new LogicException("Refund {$refundId} has no amount.");
            $status = $this->refunds->status($refundId);

            if ($status !== 'processing') {
                return new RefundView($refundId, (string) $status, $amount, $payment);
            }

            if ($result->outcome === ChargeOutcome::Unknown || $result->outcome === ChargeOutcome::Pending) {
                return new RefundView($refundId, 'processing', $amount, $payment);
            }

            if ($result->outcome !== ChargeOutcome::Succeeded) {
                // Declined, or the PSP never made it: release the reserved amount.
                $this->refunds->markFailed($refundId);

                return new RefundView($refundId, 'failed', $amount, $payment);
            }

            $refund = $payment->refund($refundId, Money::of($amount, $payment->amount->currency));
            $this->payments->save($payment);
            $this->refunds->markSucceeded($refundId);

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
}
