<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers;

use App\Application\Webhooks\HandlePspWebhook;
use App\Application\Webhooks\PspWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class PspWebhookController
{
    /** Always 2xx for a valid signature: a PSP retries anything else. */
    public function __invoke(Request $request, HandlePspWebhook $handle): JsonResponse
    {
        $request->validate([
            'id' => ['required', 'string', 'max:64'],
            'data.payment_id' => ['required', 'string'],
            'data.status' => ['required', 'string'],
            'data.psp_payment_id' => ['nullable', 'string'],
            'data.decline_reason' => ['nullable', 'string'],
        ]);

        $result = $handle->handle(new PspWebhook(
            eventId: $request->string('id')->toString(),
            paymentId: $request->string('data.payment_id')->toString(),
            status: $request->string('data.status')->toString(),
            pspPaymentId: $request->filled('data.psp_payment_id') ? $request->string('data.psp_payment_id')->toString() : null,
            declineReason: $request->filled('data.decline_reason') ? $request->string('data.decline_reason')->toString() : null,
        ));

        return new JsonResponse(['data' => ['result' => $result->value]]);
    }
}
