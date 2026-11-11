<?php

declare(strict_types=1);

use App\Infrastructure\Persistence\Models\MerchantModel;
use App\Infrastructure\Psp\PspSignature;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Creates a merchant and returns its plain API key (only the sha256 is stored).
 */
function merchantWithKey(string $name = 'Demo Store'): array
{
    $key = 'test_'.Str::random(32);
    $merchant = MerchantModel::query()->create([
        'id' => (string) Str::uuid7(),
        'name' => $name,
        'api_key_hash' => hash('sha256', $key),
    ]);

    return [$merchant->id, $key];
}

/**
 * @return array<string, mixed>
 */
function orderPayload(array $overrides = []): array
{
    return array_merge([
        'currency' => 'KZT',
        'items' => [
            ['sku' => 'SKU-TEA', 'name' => 'Green tea', 'quantity' => 2, 'unit_price' => 150_000],
            ['sku' => 'SKU-CUP', 'name' => 'Cup', 'quantity' => 1, 'unit_price' => 200_000],
        ],
    ], $overrides);
}

function createOrder(string $apiKey): string
{
    $id = test()->postJson('/api/orders', orderPayload(), ['X-Api-Key' => $apiKey])->assertCreated()->json('data.id');

    return is_string($id) ? $id : throw new RuntimeException('no order id');
}

function pay(string $apiKey, string $orderId, string $key, array $body = ['card_token' => 'tok_visa']): TestResponse
{
    return test()->postJson("/api/orders/{$orderId}/payments", $body, ['X-Api-Key' => $apiKey, 'Idempotency-Key' => $key]);
}

/**
 * POSTs a PSP webhook signed like the fake PSP does.
 *
 * @param  array<string, mixed>  $data
 */
function sendWebhook(string $eventId, array $data, ?int $timestamp = null, ?string $secret = null): TestResponse
{
    $body = json_encode(['id' => $eventId, 'type' => 'payment.'.($data['status'] ?? ''), 'data' => $data], JSON_THROW_ON_ERROR);
    $timestamp ??= now()->getTimestamp();
    $signer = new PspSignature($secret ?? config()->string('services.psp.webhook_secret'));

    return test()->call('POST', '/api/webhooks/psp', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_PSP_TIMESTAMP' => (string) $timestamp,
        'HTTP_X_PSP_SIGNATURE' => $signer->sign($timestamp, $body),
    ], $body);
}
