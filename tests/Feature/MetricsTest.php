<?php

declare(strict_types=1);

use App\Application\Ledger\IncomingEvent;
use App\Application\Ledger\RecordLedgerEntries;
use App\Application\Ports\PaymentGateway;
use App\Infrastructure\Metrics\MeteredPaymentGateway;
use App\Infrastructure\Metrics\Metrics;
use Illuminate\Support\Str;
use Tests\Doubles\ScriptedGateway;

beforeEach(function (): void {
    [, $this->key] = merchantWithKey();
    app()->instance(PaymentGateway::class, app()->make(MeteredPaymentGateway::class, ['inner' => new ScriptedGateway]));
});

function scrape(): string
{
    return test()->get('/metrics')->assertOk()->assertHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8')->getContent();
}

it('counts API requests per route template, not per URL', function (): void {
    $orderId = createOrder($this->key);
    test()->getJson("/api/orders/{$orderId}", ['X-Api-Key' => $this->key])->assertOk();

    expect(scrape())
        ->toContain('payments_http_requests_total{method="POST",route="/api/orders",status="201"} 1')
        ->toContain('payments_http_requests_total{method="GET",route="/api/orders/{order}",status="200"} 1')
        ->toContain('payments_http_request_duration_seconds_bucket');
});

it('counts idempotency outcomes from the package events', function (): void {
    $orderId = createOrder($this->key);
    pay($this->key, $orderId, 'k-1');
    pay($this->key, $orderId, 'k-1');
    pay($this->key, $orderId, 'k-1', ['card_token' => 'other']);

    expect(scrape())
        ->toContain('payments_idempotency_total{outcome="stored"} 1')
        ->toContain('payments_idempotency_total{outcome="replayed"} 1')
        ->toContain('payments_idempotency_total{outcome="mismatch"} 1');
});

it('counts outbox events and reports the outbox lag', function (): void {
    pay($this->key, createOrder($this->key), 'k-1');
    $this->travel(5)->seconds();

    $metrics = scrape();

    expect($metrics)->toContain('payments_outbox_events_total{event="stored"} 1')
        ->toContain('payments_outbox_messages{status="pending"} 1')
        ->toMatch('/payments_outbox_lag_seconds [5-9]/');

    $this->artisan('outbox:relay', ['--once' => true]);
    expect(scrape())->toContain('payments_outbox_events_total{event="published"} 1')
        ->toContain('payments_outbox_lag_seconds 0');
});

it('counts PSP calls by outcome', function (): void {
    pay($this->key, createOrder($this->key), 'k-1');

    expect(scrape())->toContain('payments_psp_calls_total{operation="charge",outcome="succeeded"} 1');
});

it('reports payments by status and the ledger balance per account', function (): void {
    pay($this->key, createOrder($this->key), 'k-1');
    app(RecordLedgerEntries::class)->handle(new IncomingEvent(
        (string) Str::uuid7(), 'payment.succeeded', ['payment_id' => (string) Str::uuid7(), 'amount' => 500_000, 'currency' => 'KZT'],
    ));

    expect(scrape())->toContain('payments_payments{status="succeeded"} 1')
        ->toContain('payments_ledger_balance{account="psp_clearing"} 500000')
        ->toContain('payments_ledger_balance{account="merchant_payable"} -500000');
});

it('counts inbox duplicates and ledger outcomes', function (): void {
    $metrics = app(Metrics::class);
    $metrics->ledger('posted');
    $metrics->inboxDuplicate('ledger');

    expect(scrape())->toContain('payments_ledger_messages_total{outcome="posted"} 1')
        ->toContain('payments_inbox_duplicates_total{consumer="ledger"} 1');
});
