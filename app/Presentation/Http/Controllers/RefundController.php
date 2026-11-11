<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers;

use App\Application\Refunds\RefundPayment;
use App\Presentation\Http\Requests\RefundPaymentRequest;
use Illuminate\Http\JsonResponse;

final readonly class RefundController
{
    public function store(RefundPaymentRequest $request, string $payment, RefundPayment $refundPayment): JsonResponse
    {
        $refund = $refundPayment->handle($request->toCommand($payment));

        return new JsonResponse(['data' => [
            'id' => $refund->id,
            'status' => $refund->status,
            'amount' => $refund->amount,
            'payment' => [
                'id' => $refund->payment->id,
                'status' => $refund->payment->status()->value,
                'refunded_amount' => $refund->payment->refunded()->amount,
            ],
        ]], 201);
    }
}
