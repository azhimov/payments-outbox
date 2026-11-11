<?php

declare(strict_types=1);

use App\Application\Ports\ChargeOutcome;
use App\Application\Ports\ChargeResult;
use App\Application\Ports\PaymentGateway;
use Illuminate\Support\Facades\DB;
use Tests\Doubles\ScriptedGateway;

beforeEach(function (): void {
    [, $this->key] = merchantWithKey();
    $this->gateway = new ScriptedGateway(new ChargeResult(ChargeOutcome::Unknown, reason: 'psp_unreachable'));
    app()->instance(PaymentGateway::class, $this->gateway);
    $this->orderId = createOrder($this->key);
    $this->paymentId = pay($this->key, $this->orderId, 'key-1')->assertAccepted()->json('data.id');
});

it('finishes a stuck payment the PSP did charge', function (): void {
    $this->gateway->statusResult = new ChargeResult(ChargeOutcome::Succeeded, 'psp_late');
    $this->travel(61)->seconds();

    $this->artisan('payments:reconcile')
        ->expectsOutputToContain('checked 1, finalized 1, still processing 0')
        ->assertSuccessful();

    expect(DB::table('payments')->where('id', $this->paymentId)->value('status'))->toBe('succeeded')
        ->and(DB::table('outbox_messages')->where('type', 'payment.succeeded')->count())->toBe(1)
        ->and($this->gateway->statusQueries)->toBe([$this->paymentId]);
});

it('fails a stuck payment the PSP never charged', function (): void {
    $this->gateway->statusResult = new ChargeResult(ChargeOutcome::NotFound);
    $this->travel(61)->seconds();

    $this->artisan('payments:reconcile')->assertSuccessful();

    expect(DB::table('payments')->where('id', $this->paymentId)->value('status'))->toBe('failed');
});

it('leaves a payment processing while the PSP still does not know', function (ChargeResult $status): void {
    $this->gateway->statusResult = $status;
    $this->travel(61)->seconds();

    $this->artisan('payments:reconcile')->expectsOutputToContain('still processing 1')->assertSuccessful();
})->with([
    'unreachable' => [new ChargeResult(ChargeOutcome::Unknown)],
    'pending' => [new ChargeResult(ChargeOutcome::Pending, 'psp_p')],
]);

it('does not touch young payments', function (): void {
    $this->travel(30)->seconds();

    $this->artisan('payments:reconcile')->expectsOutputToContain('checked 0')->assertSuccessful();
    expect($this->gateway->statusQueries)->toBe([]);
});

it('and the webhook do not apply one outcome twice, whoever comes first', function (string $first): void {
    $this->gateway->statusResult = new ChargeResult(ChargeOutcome::Succeeded, 'psp_late');
    $this->travel(61)->seconds();
    $webhook = fn () => sendWebhook('evt_1', ['payment_id' => $this->paymentId, 'status' => 'succeeded', 'psp_payment_id' => 'psp_late'])->assertOk();
    $reconcile = fn () => $this->artisan('payments:reconcile')->assertSuccessful();

    $first === 'webhook' ? [$webhook(), $reconcile()] : [$reconcile(), $webhook()];

    expect(DB::table('payments')->where('id', $this->paymentId)->value('status'))->toBe('succeeded')
        ->and(DB::table('outbox_messages')->where('type', 'payment.succeeded')->count())->toBe(1);
})->with(['webhook', 'reconcile']);

it('is scheduled every minute', function (): void {
    $this->artisan('schedule:list')->expectsOutputToContain('payments:reconcile')->assertSuccessful();
});
