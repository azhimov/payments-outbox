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
    $this->paymentId = pay($this->key, createOrder($this->key), 'pay-1')->assertCreated()->json('data.id');

    // The PSP answers the refund unclearly: 503, the refund stays processing with its amount reserved.
    $this->gateway->refundResult = new ChargeResult(ChargeOutcome::Unknown, reason: 'timeout');
    $this->postJson("/api/payments/{$this->paymentId}/refunds", ['amount' => 100_000], ['X-Api-Key' => $this->key, 'Idempotency-Key' => 'r-1'])
        ->assertServiceUnavailable();
    $this->refundId = (string) DB::table('refunds')->value('id');
});

function retryRefund(): TestResponse
{
    return test()->postJson('/api/payments/'.test()->paymentId.'/refunds', ['amount' => 100_000], ['X-Api-Key' => test()->key, 'Idempotency-Key' => 'r-1']);
}

function refundState(): array
{
    return [
        DB::table('refunds')->where('id', test()->refundId)->value('status'),
        (int) DB::table('payments')->where('id', test()->paymentId)->value('refunded_amount'),
        DB::table('outbox_messages')->where('type', 'payment.refunded')->count(),
    ];
}

it('finishes a refund the PSP did make, without the client coming back', function (): void {
    $this->gateway->refundStatusResult = new ChargeResult(ChargeOutcome::Succeeded, 'rfd_1');
    $this->travel(61)->seconds();

    $this->artisan('refunds:reconcile')
        ->expectsOutputToContain('checked 1, finalized 1, still processing 0')
        ->assertSuccessful();

    expect(refundState())->toBe(['succeeded', 100_000, 1])
        ->and($this->gateway->refundStatusQueries)->toBe([$this->refundId])
        ->and(DB::table('payments')->where('id', $this->paymentId)->value('status'))->toBe('partially_refunded');
});

it('releases the reservation of a refund the PSP never made', function (): void {
    $this->gateway->refundStatusResult = new ChargeResult(ChargeOutcome::NotFound);
    $this->travel(61)->seconds();

    $this->artisan('refunds:reconcile')->assertSuccessful();

    expect(refundState())->toBe(['failed', 0, 0]);

    // The whole amount is refundable again.
    $this->gateway->refundResult = new ChargeResult(ChargeOutcome::Succeeded, 'rfd_2');
    $this->postJson("/api/payments/{$this->paymentId}/refunds", ['amount' => 500_000], ['X-Api-Key' => $this->key, 'Idempotency-Key' => 'r-2'])
        ->assertCreated();
});

it('keeps a refund processing while the PSP still does not answer', function (): void {
    $this->gateway->refundStatusResult = new ChargeResult(ChargeOutcome::Unknown);
    $this->travel(61)->seconds();

    $this->artisan('refunds:reconcile')->expectsOutputToContain('still processing 1')->assertSuccessful();

    expect(refundState())->toBe(['processing', 0, 0]);
});

it('does not touch young refunds', function (): void {
    $this->travel(30)->seconds();

    $this->artisan('refunds:reconcile')->expectsOutputToContain('checked 0')->assertSuccessful();
    expect($this->gateway->refundStatusQueries)->toBe([]);
});

it('and a client retry never refund twice, whoever comes first', function (string $first): void {
    $this->gateway->refundResult = new ChargeResult(ChargeOutcome::Succeeded, 'rfd_1');
    $this->gateway->refundStatusResult = new ChargeResult(ChargeOutcome::Succeeded, 'rfd_1');
    $this->travel(61)->seconds();

    $retry = fn () => retryRefund()->assertCreated()->assertJsonPath('data.status', 'succeeded');
    $reconcile = fn () => $this->artisan('refunds:reconcile')->assertSuccessful();

    $first === 'client retry' ? [$retry(), $reconcile()] : [$reconcile(), $retry()];

    expect(refundState())->toBe(['succeeded', 100_000, 1])
        ->and(DB::table('refunds')->count())->toBe(1);
})->with(['client retry', 'reconcile']);

it('is scheduled every minute', function (): void {
    $this->artisan('schedule:list')->expectsOutputToContain('refunds:reconcile')->assertSuccessful();
});
