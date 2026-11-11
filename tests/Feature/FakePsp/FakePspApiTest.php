<?php

declare(strict_types=1);

use App\FakePsp\FakePspCharge;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

function pspCharge(string $token, ?string $paymentId = null, int $amount = 500_000): TestResponse
{
    return test()->postJson('/psp/payments', ['amount' => $amount, 'currency' => 'KZT', 'card_token' => $token], [
        'Idempotency-Key' => $paymentId ?? (string) Str::uuid7(),
    ]);
}

it('charges by card token outcome', function (string $token, int $status, ?string $outcome): void {
    $response = pspCharge($token);

    $response->assertStatus($status);
    if ($outcome !== null) {
        $response->assertJsonPath('status', $outcome);
        expect($response->json('id'))->toStartWith('psp_');
    }
})->with([
    'success' => ['tok_success', 201, 'succeeded'],
    'decline' => ['tok_decline', 201, 'declined'],
    'pending' => ['tok_pending', 201, 'pending'],
    'error, not charged' => ['tok_error', 503, null],
]);

it('reports why a charge was declined', function (): void {
    pspCharge('tok_decline')->assertJsonPath('decline_reason', 'insufficient_funds');
});

it('takes the money but answers late on timeout', function (): void {
    config(['fake_psp.timeout_sleep_ms' => 10]);
    $paymentId = (string) Str::uuid7();

    pspCharge('tok_timeout', $paymentId)->assertCreated()->assertJsonPath('status', 'succeeded');

    expect(FakePspCharge::query()->where('payment_id', $paymentId)->count())->toBe(1);
});

it('never charges twice for one idempotency key', function (): void {
    $paymentId = (string) Str::uuid7();

    $first = pspCharge('tok_success', $paymentId)->assertCreated();
    $second = pspCharge('tok_decline', $paymentId)->assertOk();

    expect($second->json('id'))->toBe($first->json('id'))
        ->and($second->json('status'))->toBe('succeeded')
        ->and(FakePspCharge::query()->where('payment_id', $paymentId)->count())->toBe(1);
});

it('rejects a reused key with a different amount', function (): void {
    $paymentId = (string) Str::uuid7();
    pspCharge('tok_success', $paymentId, 100);

    pspCharge('tok_success', $paymentId, 200)->assertUnprocessable();
});

it('does not remember a failed 503 attempt, so a retry charges', function (): void {
    $paymentId = (string) Str::uuid7();
    pspCharge('tok_error', $paymentId)->assertServiceUnavailable();

    pspCharge('tok_success', $paymentId)->assertCreated();
});

it('needs an idempotency key', function (): void {
    $this->postJson('/psp/payments', ['amount' => 1, 'currency' => 'KZT', 'card_token' => 'tok_success'])->assertStatus(400);
});

it('answers the status of a payment by our payment id', function (): void {
    $paymentId = (string) Str::uuid7();
    pspCharge('tok_pending', $paymentId);

    $this->getJson("/psp/payments/{$paymentId}")->assertOk()->assertJsonPath('status', 'pending');
    $this->getJson('/psp/payments/'.Str::uuid7())->assertNotFound();
});

it('draws random outcomes for tok_visa from the configured rates', function (string $rate, int $status, ?string $outcome): void {
    config(['fake_psp.rates' => ['decline' => 0, 'pending' => 0, 'timeout' => 0, 'error' => 0, $rate => 1.0], 'fake_psp.timeout_sleep_ms' => 0]);

    $response = pspCharge('tok_visa')->assertStatus($status);

    if ($outcome !== null) {
        $response->assertJsonPath('status', $outcome);
    }
})->with([
    'decline' => ['decline', 201, 'declined'],
    'pending' => ['pending', 201, 'pending'],
    'timeout' => ['timeout', 201, 'succeeded'],
    'error' => ['error', 503, null],
]);

it('succeeds tok_visa when no failure is drawn', function (): void {
    config(['fake_psp.rates' => ['decline' => 0, 'pending' => 0, 'timeout' => 0, 'error' => 0]]);

    pspCharge('tok_visa')->assertCreated()->assertJsonPath('status', 'succeeded');
});

it('refunds a captured charge once per refund key, up to the captured amount', function (): void {
    $paymentId = (string) Str::uuid7();
    pspCharge('tok_success', $paymentId, 1_000);

    $first = $this->postJson("/psp/payments/{$paymentId}/refunds", ['amount' => 600], ['Idempotency-Key' => 'rf-1'])->assertCreated();
    $this->postJson("/psp/payments/{$paymentId}/refunds", ['amount' => 600], ['Idempotency-Key' => 'rf-1'])->assertOk()->assertJsonPath('id', $first->json('id'));
    $this->postJson("/psp/payments/{$paymentId}/refunds", ['amount' => 401], ['Idempotency-Key' => 'rf-2'])->assertUnprocessable();
    $this->postJson("/psp/payments/{$paymentId}/refunds", ['amount' => 400], ['Idempotency-Key' => 'rf-3'])->assertCreated();
    $this->postJson('/psp/payments/'.Str::uuid7().'/refunds', ['amount' => 1], ['Idempotency-Key' => 'rf-4'])->assertNotFound();
    $this->postJson("/psp/payments/{$paymentId}/refunds", ['amount' => 1])->assertStatus(400);
});
