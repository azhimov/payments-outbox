<?php

declare(strict_types=1);

namespace App\FakePsp;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * A pretend acquirer. Idempotent per Idempotency-Key (the merchant's payment id):
 * a repeated charge returns the stored result and never charges twice.
 *
 * Card tokens: tok_success, tok_decline, tok_pending, tok_timeout (charged, answer late),
 * tok_error (503, not charged), tok_visa (random by config fake_psp.rates).
 */
final class FakePspController
{
    public function charge(Request $request): JsonResponse
    {
        $paymentId = $request->header('Idempotency-Key');
        $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'string', 'size:3'],
            'card_token' => ['required', 'string'],
            'webhook_url' => ['nullable', 'url'],
        ]);
        $amount = $request->integer('amount');
        $currency = $request->string('currency')->toString();
        $webhookUrl = $request->filled('webhook_url') ? $request->string('webhook_url')->toString() : null;

        if (! is_string($paymentId) || $paymentId === '') {
            return new JsonResponse(['error' => 'Idempotency-Key header is required'], 400);
        }

        $this->latency();

        $existing = FakePspCharge::query()->where('payment_id', $paymentId)->first();
        if ($existing !== null) {
            if ($existing->amount !== $amount || $existing->currency !== $currency) {
                return new JsonResponse(['error' => 'Idempotency-Key reused with a different amount'], 422);
            }

            return new JsonResponse($existing->toApi());
        }

        $scenario = $this->scenario($request->string('card_token')->toString());

        if ($scenario === 'error') {
            return new JsonResponse(['error' => 'Temporarily unavailable'], 503);
        }

        $charge = $this->store($paymentId, $amount, $currency, $webhookUrl, $scenario);

        if ($scenario === 'timeout') {
            // The money is taken, but the merchant will not hear about it in time.
            usleep(max(0, config()->integer('fake_psp.timeout_sleep_ms')) * 1000);
        }

        return new JsonResponse($charge->toApi(), 201);
    }

    /**
     * Refunds a captured charge, once per Idempotency-Key (the merchant's refund id).
     */
    public function refund(Request $request, string $paymentId): JsonResponse
    {
        $refundId = $request->header('Idempotency-Key');
        $request->validate(['amount' => ['required', 'integer', 'min:1']]);
        $amount = $request->integer('amount');

        if (! is_string($refundId) || $refundId === '') {
            return new JsonResponse(['error' => 'Idempotency-Key header is required'], 400);
        }

        $existing = FakePspRefund::query()->where('refund_id', $refundId)->first();
        if ($existing !== null) {
            return new JsonResponse($existing->toApi());
        }

        $charge = FakePspCharge::query()->where('payment_id', $paymentId)->where('status', 'succeeded')->first();
        if ($charge === null) {
            return new JsonResponse(['error' => 'Unknown payment'], 404);
        }

        $refunded = FakePspRefund::query()->where('payment_id', $paymentId)->sum('amount');
        if ($amount > $charge->amount - (int) $refunded) {
            return new JsonResponse(['error' => 'refund_exceeds_capture'], 422);
        }

        FakePspRefund::query()->insertOrIgnore([
            'id' => 'rfd_'.Str::lower(Str::random(24)),
            'refund_id' => $refundId,
            'payment_id' => $paymentId,
            'amount' => $amount,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return new JsonResponse(FakePspRefund::query()->where('refund_id', $refundId)->firstOrFail()->toApi(), 201);
    }

    public function show(string $paymentId): JsonResponse
    {
        $charge = FakePspCharge::query()->where('payment_id', $paymentId)->first();

        return $charge === null
            ? new JsonResponse(['error' => 'Unknown payment'], 404)
            : new JsonResponse($charge->toApi());
    }

    private function store(string $paymentId, int $amount, string $currency, ?string $webhookUrl, string $scenario): FakePspCharge
    {
        $status = match ($scenario) {
            'decline' => 'declined',
            'pending' => 'pending',
            default => 'succeeded',
        };

        // insertOrIgnore: two concurrent first attempts with one key still charge once.
        FakePspCharge::query()->insertOrIgnore([
            'id' => 'psp_'.Str::lower(Str::random(24)),
            'payment_id' => $paymentId,
            'amount' => $amount,
            'currency' => $currency,
            'status' => $status,
            'decline_reason' => $status === 'declined' ? 'insufficient_funds' : null,
            'final_status' => $status === 'pending' ? 'succeeded' : null,
            'webhook_url' => $webhookUrl,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return FakePspCharge::query()->where('payment_id', $paymentId)->firstOrFail();
    }

    private function scenario(string $token): string
    {
        $fixed = [
            'tok_success' => 'success',
            'tok_decline' => 'decline',
            'tok_pending' => 'pending',
            'tok_timeout' => 'timeout',
            'tok_error' => 'error',
        ];

        if (isset($fixed[$token])) {
            return $fixed[$token];
        }

        $roll = random_int(0, 999_999) / 1_000_000;
        foreach (['error', 'timeout', 'decline', 'pending'] as $outcome) {
            $rate = config("fake_psp.rates.{$outcome}");
            $roll -= is_numeric($rate) ? (float) $rate : 0.0;

            if ($roll < 0) {
                return $outcome;
            }
        }

        return 'success';
    }

    private function latency(): void
    {
        $ms = config('fake_psp.latency_ms');

        if (is_numeric($ms) && (int) $ms > 0) {
            usleep((int) $ms * 1000);
        }
    }
}
