<?php

declare(strict_types=1);

namespace App\Application\Payments;

use App\Application\Ports\PaymentRepository;
use App\Domain\Payment\Payment;

final readonly class GetPayment
{
    public function __construct(private PaymentRepository $payments) {}

    public function handle(string $paymentId, string $merchantId): ?Payment
    {
        return $this->payments->find($paymentId, $merchantId);
    }
}
