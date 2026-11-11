<?php

declare(strict_types=1);

use App\Application\Ports\ChargeOutcome;
use App\Application\Ports\ChargeResult;
use App\Application\Ports\PaymentGateway;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Doubles\ScriptedGateway;

beforeEach(function (): void {
    [$this->merchantId, $this->key] = merchantWithKey();
    $this->gateway = new ScriptedGateway;
    app()->instance(PaymentGateway::class, $this->gateway);
    $this->orderId = createOrder($this->key);
});

function outboxRows(): Collection
{
    return DB::table('outbox_messages')->get();
}

it('charges once, marks the order paid and stores payment.succeeded in the outbox', function (): void {
    $response = pay($this->key, $this->orderId, 'key-1')->assertCreated()
        ->assertJsonPath('data.status', 'succeeded')
        ->assertJsonPath('data.amount', 500_000)
        ->assertJsonPath('data.order_id', $this->orderId);

    $paymentId = $response->json('data.id');
    $message = outboxRows()->sole();

    expect($this->gateway->charges)->toHaveCount(1)
        ->and($this->gateway->charges[0]->paymentId)->toBe($paymentId)
        ->and($this->gateway->charges[0]->cardToken)->toBe('tok_visa')
        ->and(DB::table('orders')->where('id', $this->orderId)->value('status'))->toBe('paid')
        ->and($message->topic)->toBe('payments')
        ->and($message->type)->toBe('payment.succeeded')
        ->and($message->aggregate_id)->toBe($this->orderId)
        ->and(json_decode($message->payload, true))->toMatchArray([
            'payment_id' => $paymentId, 'order_id' => $this->orderId, 'amount' => 500_000, 'currency' => 'KZT', 'psp_payment_id' => 'psp_test',
        ]);
});

it('replays the stored response for a retry with the same key, without charging again', function (): void {
    $first = pay($this->key, $this->orderId, 'key-1')->assertCreated();
    $retry = pay($this->key, $this->orderId, 'key-1')->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    expect($retry->json())->toBe($first->json())
        ->and($this->gateway->charges)->toHaveCount(1)
        ->and(outboxRows())->toHaveCount(1);
});

it('rejects the same key with a different body', function (): void {
    pay($this->key, $this->orderId, 'key-1');

    pay($this->key, $this->orderId, 'key-1', ['card_token' => 'tok_other'])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'idempotency_key_reused');
});

it('answers 409 to a concurrent retry while the first request is still charging', function (): void {
    $concurrent = null;
    $this->gateway->during = function () use (&$concurrent): void {
        $concurrent = pay($this->key, $this->orderId, 'key-1');
    };

    pay($this->key, $this->orderId, 'key-1')->assertCreated();

    $concurrent->assertConflict()->assertJsonPath('error.code', 'idempotency_request_in_progress');
    expect($this->gateway->charges)->toHaveCount(1);
});

it('needs an Idempotency-Key', function (): void {
    $this->postJson("/api/orders/{$this->orderId}/payments", ['card_token' => 'tok_visa'], ['X-Api-Key' => $this->key])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'idempotency_key_missing');
});

it('refuses to pay an order twice, whatever key the client uses', function (): void {
    pay($this->key, $this->orderId, 'key-1')->assertCreated();

    pay($this->key, $this->orderId, 'key-2')->assertConflict()->assertJsonPath('error.code', 'order_already_paid');
    expect($this->gateway->charges)->toHaveCount(1);
});

it('refuses a second payment while the first is still processing', function (): void {
    $this->gateway->chargeResult = new ChargeResult(ChargeOutcome::Unknown, reason: 'psp_http_503');
    pay($this->key, $this->orderId, 'key-1')->assertAccepted();

    pay($this->key, $this->orderId, 'key-2')->assertConflict()->assertJsonPath('error.code', 'payment_in_progress');
    expect($this->gateway->charges)->toHaveCount(1);
});

it('keeps an unknown outcome processing and answers 202', function (ChargeResult $result): void {
    $this->gateway->chargeResult = $result;

    pay($this->key, $this->orderId, 'key-1')->assertAccepted()->assertJsonPath('data.status', 'processing');

    expect(DB::table('orders')->where('id', $this->orderId)->value('status'))->toBe('pending')
        ->and(outboxRows())->toHaveCount(0);
})->with([
    'timeout or 5xx' => [new ChargeResult(ChargeOutcome::Unknown, reason: 'psp_unreachable')],
    'pending at the PSP' => [new ChargeResult(ChargeOutcome::Pending, 'psp_pending')],
]);

it('remembers the PSP reference of a pending charge', function (): void {
    $this->gateway->chargeResult = new ChargeResult(ChargeOutcome::Pending, 'psp_pending');

    $id = pay($this->key, $this->orderId, 'key-1')->json('data.id');

    expect(DB::table('payments')->where('id', $id)->value('psp_payment_id'))->toBe('psp_pending');
});

it('records a decline, keeps the order payable and lets the client try another card', function (): void {
    $this->gateway->chargeResult = new ChargeResult(ChargeOutcome::Declined, 'psp_x', 'insufficient_funds');

    pay($this->key, $this->orderId, 'key-1')->assertCreated()
        ->assertJsonPath('data.status', 'declined')
        ->assertJsonPath('data.failure_reason', 'insufficient_funds');

    expect(outboxRows())->toHaveCount(0);

    $this->gateway->chargeResult = new ChargeResult(ChargeOutcome::Succeeded, 'psp_y');
    pay($this->key, $this->orderId, 'key-2')->assertCreated()->assertJsonPath('data.status', 'succeeded');
});

it('scopes idempotency keys to the merchant', function (): void {
    [, $otherKey] = merchantWithKey('Other Shop');
    $otherOrder = createOrder($otherKey);

    pay($this->key, $this->orderId, 'shared-key')->assertCreated();
    pay($otherKey, $otherOrder, 'shared-key')->assertCreated()->assertHeaderMissing('Idempotent-Replayed');

    expect($this->gateway->charges)->toHaveCount(2);
});

it('does not pay an order of another merchant', function (): void {
    [, $otherKey] = merchantWithKey('Other Shop');

    pay($otherKey, $this->orderId, 'key-1')->assertNotFound();
    expect($this->gateway->charges)->toBe([]);
});

it('shows a payment to its merchant only', function (): void {
    $id = pay($this->key, $this->orderId, 'key-1')->json('data.id');
    [, $otherKey] = merchantWithKey('Other Shop');

    $this->getJson("/api/payments/{$id}", ['X-Api-Key' => $this->key])->assertOk()->assertJsonPath('data.status', 'succeeded');
    $this->getJson("/api/payments/{$id}", ['X-Api-Key' => $otherKey])->assertNotFound();
    $this->getJson('/api/payments/'.Str::uuid7(), ['X-Api-Key' => $this->key])->assertNotFound();
});
