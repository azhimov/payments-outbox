<?php

declare(strict_types=1);

namespace App\Presentation\Http\Requests;

use App\Application\Refunds\RefundPaymentCommand;
use App\Presentation\Http\Middleware\AuthenticateMerchant;
use Illuminate\Foundation\Http\FormRequest;
use Ramsey\Uuid\Uuid;

final class RefundPaymentRequest extends FormRequest
{
    /** Namespace for refund ids derived from Idempotency-Keys. */
    private const REFUND_ID_NAMESPACE = '0a1f6a2e-8a0d-5b9e-9c3d-2f6c7f0e4b11';

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return ['amount' => ['required', 'integer', 'min:1']];
    }

    public function toCommand(string $paymentId): RefundPaymentCommand
    {
        $merchantId = AuthenticateMerchant::merchantId($this);
        $key = (string) $this->header('Idempotency-Key');

        return new RefundPaymentCommand(
            merchantId: $merchantId,
            paymentId: $paymentId,
            // Same request retried -> same refund id -> no second refund at the PSP.
            refundId: Uuid::uuid5(self::REFUND_ID_NAMESPACE, $merchantId.':'.$paymentId.':'.$key)->toString(),
            amount: $this->integer('amount'),
        );
    }
}
