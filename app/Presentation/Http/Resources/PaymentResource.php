<?php

declare(strict_types=1);

namespace App\Presentation\Http\Resources;

use App\Domain\Payment\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Payment $resource
 */
final class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payment = $this->resource;

        return [
            'id' => $payment->id,
            'order_id' => $payment->orderId,
            'status' => $payment->status()->value,
            'amount' => $payment->amount->amount,
            'currency' => $payment->amount->currency->code,
            'refunded_amount' => $payment->refunded()->amount,
            'failure_reason' => $payment->failureReason(),
        ];
    }
}
