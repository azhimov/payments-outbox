<?php

declare(strict_types=1);

use App\Application\Ports\ChargeOutcome;
use App\Application\Ports\ChargeResult;
use App\Application\Ports\PaymentGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Doubles\ScriptedGateway;

beforeEach(function (): void {
    [, $this->key] = merchantWithKey();
    $this->gateway = new ScriptedGateway;
    app()->instance(PaymentGateway::class, $this->gateway);
    $this->orderId = createOrder($this->key); // 500 000
    $this->paymentId = pay($this->key, $this->orderId, 'pay-1')->assertCreated()->json('data.id');
});

function refund(string $apiKey, string $paymentId, int $amount, string $key): TestResponse
{
    return test()->postJson("/api/payments/{$paymentId}/refunds", ['amount' => $amount], ['X-Api-Key' => $apiKey, 'Idempotency-Key' => $key]);
}

it('refunds in full and publishes payment.refunded for the same order', function (): void {
    refund($this->key, $this->paymentId, 500_000, 'r-1')->assertCreated()
        ->assertJsonPath('data.status', 'succeeded')
        ->assertJsonPath('data.amount', 500_000)
        ->assertJsonPath('data.payment.status', 'refunded')
        ->assertJsonPath('data.payment.refunded_amount', 500_000);

    $event = DB::table('outbox_messages')->where('type', 'payment.refunded')->sole();
    expect($event->aggregate_id)->toBe($this->orderId)
        ->and(json_decode($event->payload, true))->toMatchArray(['payment_id' => $this->paymentId, 'amount' => 500_000, 'currency' => 'KZT'])
        ->and($this->gateway->refunds[0]->paymentId)->toBe($this->paymentId);
});

it('refunds partially, more than once, up to the captured amount', function (): void {
    refund($this->key, $this->paymentId, 100_000, 'r-1')->assertCreated()->assertJsonPath('data.payment.status', 'partially_refunded');
    refund($this->key, $this->paymentId, 400_000, 'r-2')->assertCreated()->assertJsonPath('data.payment.status', 'refunded');

    refund($this->key, $this->paymentId, 1, 'r-3')->assertConflict()->assertJsonPath('error.code', 'invalid_payment_state');
    expect(DB::table('outbox_messages')->where('type', 'payment.refunded')->count())->toBe(2);
});

it('refuses more than what is left', function (): void {
    refund($this->key, $this->paymentId, 100_000, 'r-1');

    refund($this->key, $this->paymentId, 400_001, 'r-2')->assertUnprocessable()->assertJsonPath('error.code', 'refund_not_allowed');
    expect($this->gateway->refunds)->toHaveCount(1);
});

it('replays a retried refund instead of refunding again', function (): void {
    $first = refund($this->key, $this->paymentId, 100_000, 'r-1')->assertCreated();
    $retry = refund($this->key, $this->paymentId, 100_000, 'r-1')->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    expect($retry->json())->toBe($first->json())
        ->and($this->gateway->refunds)->toHaveCount(1);
});

it('answers 503 on an unclear PSP answer, and a retry with the same key refunds once', function (): void {
    $this->gateway->refundResult = new ChargeResult(ChargeOutcome::Unknown, reason: 'timeout');

    refund($this->key, $this->paymentId, 100_000, 'r-1')->assertServiceUnavailable()
        ->assertJsonPath('error.code', 'psp_unavailable')
        ->assertHeader('Retry-After', '2');

    // The amount stays reserved: another refund cannot take it meanwhile.
    refund($this->key, $this->paymentId, 400_001, 'r-2')->assertUnprocessable();

    $this->gateway->refundResult = new ChargeResult(ChargeOutcome::Succeeded, 'psp_refund');
    refund($this->key, $this->paymentId, 100_000, 'r-1')->assertCreated()->assertJsonPath('data.status', 'succeeded');

    expect(array_unique(array_map(fn ($r) => $r->refundId, $this->gateway->refunds)))->toHaveCount(1)
        ->and(DB::table('refunds')->count())->toBe(1);
});

it('records a refund the PSP refused', function (): void {
    $this->gateway->refundResult = new ChargeResult(ChargeOutcome::Declined, reason: 'refund_exceeds_capture');

    refund($this->key, $this->paymentId, 100_000, 'r-1')->assertCreated()->assertJsonPath('data.status', 'failed');
    expect(DB::table('outbox_messages')->where('type', 'payment.refunded')->count())->toBe(0);

    // The reservation is released: the full amount can be refunded again.
    $this->gateway->refundResult = new ChargeResult(ChargeOutcome::Succeeded, 'psp_refund');
    refund($this->key, $this->paymentId, 500_000, 'r-2')->assertCreated()->assertJsonPath('data.status', 'succeeded');
});

it('does not refund a payment of another merchant or one that does not exist', function (): void {
    [, $otherKey] = merchantWithKey('Other Shop');

    refund($otherKey, $this->paymentId, 1, 'r-1')->assertNotFound();
    refund($this->key, '01970000-0000-7000-8000-00000000dead', 1, 'r-1')->assertNotFound();
});

it('does not refund a payment that has not succeeded', function (): void {
    $order = createOrder($this->key);
    $this->gateway->chargeResult = new ChargeResult(ChargeOutcome::Declined, 'x', 'insufficient_funds');
    $declined = pay($this->key, $order, 'pay-2')->json('data.id');

    refund($this->key, $declined, 1, 'r-1')->assertConflict()->assertJsonPath('error.code', 'invalid_payment_state');
});

it('validates the amount and needs a key', function (): void {
    refund($this->key, $this->paymentId, 0, 'r-1')->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');
    $this->postJson("/api/payments/{$this->paymentId}/refunds", ['amount' => 1], ['X-Api-Key' => $this->key])->assertStatus(400);
});
