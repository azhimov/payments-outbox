<?php

declare(strict_types=1);

use App\Application\Ports\ChargeOutcome;
use App\Application\Ports\ChargeResult;
use App\Application\Ports\PaymentGateway;
use Illuminate\Support\Facades\DB;
use Tests\Doubles\ScriptedGateway;

beforeEach(function (): void {
    [, $this->key] = merchantWithKey();
    $this->gateway = new ScriptedGateway(new ChargeResult(ChargeOutcome::Pending, 'psp_pending'));
    app()->instance(PaymentGateway::class, $this->gateway);
    $this->orderId = createOrder($this->key);
    $this->paymentId = pay($this->key, $this->orderId, 'key-1')->assertAccepted()->json('data.id');
});

function paymentStatus(string $id): string
{
    return (string) DB::table('payments')->where('id', $id)->value('status');
}

it('passes the webhook URL to the PSP', function (): void {
    expect($this->gateway->charges[0]->webhookUrl)->toBe(config('services.psp.webhook_url'));
});

it('finishes a pending payment from a signed webhook', function (): void {
    sendWebhook('evt_1', ['payment_id' => $this->paymentId, 'status' => 'succeeded', 'psp_payment_id' => 'psp_pending'])
        ->assertOk()
        ->assertJsonPath('data.result', 'processed');

    expect(paymentStatus($this->paymentId))->toBe('succeeded')
        ->and(DB::table('orders')->where('id', $this->orderId)->value('status'))->toBe('paid')
        ->and(DB::table('outbox_messages')->where('type', 'payment.succeeded')->count())->toBe(1);
});

it('ignores a duplicate webhook with the same event id', function (): void {
    sendWebhook('evt_1', ['payment_id' => $this->paymentId, 'status' => 'succeeded', 'psp_payment_id' => 'psp_pending'])->assertOk();

    sendWebhook('evt_1', ['payment_id' => $this->paymentId, 'status' => 'succeeded', 'psp_payment_id' => 'psp_pending'])
        ->assertOk()
        ->assertJsonPath('data.result', 'duplicate');

    expect(DB::table('outbox_messages')->count())->toBe(1)
        ->and(DB::table('inbox_messages')->where('consumer', 'psp-webhooks')->count())->toBe(1);
});

it('records a decline from a webhook', function (): void {
    sendWebhook('evt_2', ['payment_id' => $this->paymentId, 'status' => 'declined', 'decline_reason' => 'do_not_honor'])->assertOk();

    expect(paymentStatus($this->paymentId))->toBe('declined')
        ->and(DB::table('payments')->where('id', $this->paymentId)->value('failure_reason'))->toBe('do_not_honor');
});

it('is a no-op when the payment is already final, even under a new event id', function (): void {
    sendWebhook('evt_1', ['payment_id' => $this->paymentId, 'status' => 'succeeded', 'psp_payment_id' => 'psp_pending']);

    sendWebhook('evt_late', ['payment_id' => $this->paymentId, 'status' => 'declined'])->assertOk();

    expect(paymentStatus($this->paymentId))->toBe('succeeded')
        ->and(DB::table('outbox_messages')->count())->toBe(1);
});

it('ignores unknown payments and statuses', function (array $data): void {
    sendWebhook('evt_x', $data)->assertOk()->assertJsonPath('data.result', 'ignored');
})->with([
    'unknown payment' => [['payment_id' => '01970000-0000-7000-8000-00000000dead', 'status' => 'succeeded']],
    'unknown status' => [['payment_id' => '01970000-0000-7000-8000-00000000beef', 'status' => 'chargeback']],
]);

it('rejects bad signatures and stale webhooks', function (?int $timestamp, ?string $secret): void {
    sendWebhook('evt_1', ['payment_id' => $this->paymentId, 'status' => 'succeeded'], $timestamp, $secret)
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'invalid_signature');

    expect(paymentStatus($this->paymentId))->toBe('processing');
})->with([
    'wrong secret' => [null, 'not-the-secret'],
    'replayed after 10 minutes' => [now()->subMinutes(10)->getTimestamp(), null],
]);

it('rejects a webhook without signature headers', function (): void {
    $this->postJson('/api/webhooks/psp', ['id' => 'evt_1'])->assertUnauthorized();
});
