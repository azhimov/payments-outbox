<?php

declare(strict_types=1);

namespace App\Application\Payments;

use App\Application\Payments\Exceptions\OrderAlreadyPaid;
use App\Application\Payments\Exceptions\OrderNotFound;
use App\Application\Ports\ChargeRequest;
use App\Application\Ports\IdGenerator;
use App\Application\Ports\OrderRepository;
use App\Application\Ports\PaymentGateway;
use App\Application\Ports\PaymentRepository;
use App\Application\Ports\TransactionRunner;
use App\Domain\Order\OrderStatus;
use App\Domain\Payment\Payment;

/**
 * 1. tx1: store the payment as processing (a unique index allows one live payment per order);
 * 2. charge at the PSP, outside any transaction (no locks held during HTTP);
 * 3. tx2: apply the answer (PaymentOutcomes), with the outbox event on success.
 * A crash between 1 and 3 leaves a processing payment that reconcile finishes.
 */
final readonly class CreatePayment
{
    public function __construct(
        private OrderRepository $orders,
        private PaymentRepository $payments,
        private PaymentGateway $gateway,
        private PaymentOutcomes $outcomes,
        private IdGenerator $ids,
        private TransactionRunner $transaction,
        private ?string $webhookUrl = null,
    ) {}

    public function handle(CreatePaymentCommand $command): Payment
    {
        $order = $this->orders->find($command->orderId, $command->merchantId) ?? throw new OrderNotFound;

        if ($order->status() === OrderStatus::Paid) {
            throw new OrderAlreadyPaid;
        }

        $payment = Payment::create($this->ids->next(), $order->id, $command->merchantId, $order->total());
        $payment->startProcessing();
        $this->transaction->run(fn () => $this->payments->save($payment));

        $result = $this->gateway->charge(new ChargeRequest($payment->id, $payment->amount, $command->cardToken, $this->webhookUrl));

        return $this->outcomes->apply($payment->id, $result);
    }
}
