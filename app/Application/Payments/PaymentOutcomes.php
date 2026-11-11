<?php

declare(strict_types=1);

namespace App\Application\Payments;

use App\Application\Ports\ChargeOutcome;
use App\Application\Ports\ChargeResult;
use App\Application\Ports\IntegrationEvent;
use App\Application\Ports\IntegrationEvents;
use App\Application\Ports\OrderRepository;
use App\Application\Ports\PaymentRepository;
use App\Application\Ports\TransactionRunner;
use App\Domain\Payment\Payment;
use App\Domain\Payment\PaymentStatus;
use LogicException;

/**
 * Applies what the PSP said to a payment. The one place that finalizes payments:
 * the API call, the webhook and reconcile all come through here.
 *
 * Idempotent: the payment row is locked, and a payment that is no longer processing
 * is left alone, so whoever arrives second (webhook vs reconcile vs API) is a no-op.
 * On success the payment, the order and the payment.succeeded event commit together.
 */
final readonly class PaymentOutcomes
{
    public function __construct(
        private PaymentRepository $payments,
        private OrderRepository $orders,
        private IntegrationEvents $events,
        private TransactionRunner $transaction,
    ) {}

    public function apply(string $paymentId, ChargeResult $result): Payment
    {
        return $this->transaction->run(function () use ($paymentId, $result): Payment {
            $payment = $this->payments->findForUpdate($paymentId)
                ?? throw new LogicException("Payment {$paymentId} does not exist.");

            if ($payment->status() !== PaymentStatus::Processing) {
                return $payment;
            }

            match ($result->outcome) {
                ChargeOutcome::Succeeded => $this->succeed($payment, $result),
                ChargeOutcome::Declined => $payment->decline($result->reason ?? 'declined'),
                // The PSP confirms it never took the money: safe to call it failed.
                ChargeOutcome::NotFound => $payment->fail('not_charged_at_psp'),
                ChargeOutcome::Pending => $result->pspPaymentId === null ? null : $payment->recordPspReference($result->pspPaymentId),
                // Nobody knows yet: stay processing, the webhook or reconcile will tell.
                ChargeOutcome::Unknown => null,
            };

            $this->payments->save($payment);

            return $payment;
        });
    }

    private function succeed(Payment $payment, ChargeResult $result): void
    {
        $payment->succeed($result->pspPaymentId ?? throw new LogicException('A successful charge needs a PSP reference.'));

        $order = $this->orders->find($payment->orderId, $payment->merchantId)
            ?? throw new LogicException("Order {$payment->orderId} of payment {$payment->id} does not exist.");
        $order->markPaid();
        $this->orders->save($order);

        $this->events->publish(new IntegrationEvent(
            topic: 'payments',
            type: 'payment.succeeded',
            payload: [
                'payment_id' => $payment->id,
                'order_id' => $payment->orderId,
                'merchant_id' => $payment->merchantId,
                'amount' => $payment->amount->amount,
                'currency' => $payment->amount->currency->code,
                'psp_payment_id' => $payment->pspPaymentId(),
            ],
            aggregateId: $payment->orderId,
        ));
    }
}
