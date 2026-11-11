<?php

declare(strict_types=1);

use App\FakePsp\FakePspCharge;
use App\Infrastructure\Psp\PspSignature;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Http::fake(['merchant.test/*' => Http::response(['ok' => true])]);
    config(['fake_psp.webhook_delay_ms' => 1000, 'fake_psp.webhook_duplicate_rate' => 0.0]);
});

function pendingCharge(): string
{
    $paymentId = (string) Str::uuid7();
    test()->postJson('/psp/payments', ['amount' => 100, 'currency' => 'KZT', 'card_token' => 'tok_pending', 'webhook_url' => 'http://merchant.test/api/webhooks/psp'], ['Idempotency-Key' => $paymentId])->assertCreated();

    return $paymentId;
}

it('settles a pending charge after the delay and sends a signed webhook', function (): void {
    $paymentId = pendingCharge();

    $this->artisan('psp:deliver-webhooks', ['--once' => true]);
    Http::assertNothingSent();

    $this->travel(2)->seconds();
    $this->artisan('psp:deliver-webhooks', ['--once' => true]);

    $charge = FakePspCharge::query()->where('payment_id', $paymentId)->sole();
    expect($charge->status)->toBe('succeeded')
        ->and($charge->webhook_sent_at)->not->toBeNull();

    Http::assertSent(function (Request $request) use ($paymentId, $charge): bool {
        $signer = new PspSignature(config()->string('services.psp.webhook_secret'));

        return $request['id'] === $charge->webhook_event_id
            && $request['data']['payment_id'] === $paymentId
            && $request['data']['status'] === 'succeeded'
            && $signer->verify($request->header('X-Psp-Timestamp')[0], $request->body(), $request->header('X-Psp-Signature')[0], now()->getTimestamp(), 300);
    });
});

it('sometimes sends the same webhook twice, with the same event id', function (): void {
    config(['fake_psp.webhook_duplicate_rate' => 1.0]);
    pendingCharge();
    $this->travel(2)->seconds();

    $this->artisan('psp:deliver-webhooks', ['--once' => true]);

    $sent = Http::recorded();
    expect($sent)->toHaveCount(2)
        ->and($sent[0][0]['id'])->toBe($sent[1][0]['id']);
});

it('sends each webhook once', function (): void {
    pendingCharge();
    $this->travel(2)->seconds();

    $this->artisan('psp:deliver-webhooks', ['--once' => true]);
    $this->artisan('psp:deliver-webhooks', ['--once' => true]);

    Http::assertSentCount(1);
});
