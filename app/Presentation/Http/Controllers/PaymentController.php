<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers;

use App\Application\Payments\CreatePayment;
use App\Application\Payments\GetPayment;
use App\Domain\Payment\PaymentStatus;
use App\Presentation\Http\Middleware\AuthenticateMerchant;
use App\Presentation\Http\Requests\CreatePaymentRequest;
use App\Presentation\Http\Resources\PaymentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class PaymentController
{
    /**
     * 201 when the outcome is final, 202 while it is still processing (pending at the PSP
     * or unknown after a timeout): poll GET /api/payments/{id}, the stored 202 is what
     * a retry with the same Idempotency-Key gets back.
     */
    public function store(CreatePaymentRequest $request, string $order, CreatePayment $createPayment): JsonResponse
    {
        $payment = $createPayment->handle($request->toCommand($order));

        return (new PaymentResource($payment))
            ->response()
            ->setStatusCode($payment->status() === PaymentStatus::Processing ? 202 : 201);
    }

    public function show(Request $request, string $payment, GetPayment $getPayment): PaymentResource
    {
        $found = $getPayment->handle($payment, AuthenticateMerchant::merchantId($request));

        if ($found === null) {
            throw new NotFoundHttpException('Payment not found.');
        }

        return new PaymentResource($found);
    }
}
