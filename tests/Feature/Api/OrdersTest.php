<?php

declare(strict_types=1);

beforeEach(function (): void {
    [$this->merchantId, $this->key] = merchantWithKey();
});

it('creates an order and returns its total in minor units', function (): void {
    $response = $this->postJson('/api/orders', orderPayload(), ['X-Api-Key' => $this->key]);

    $response->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.currency', 'KZT')
        ->assertJsonPath('data.total_amount', 500_000)
        ->assertJsonPath('data.items.0.sku', 'SKU-TEA')
        ->assertJsonPath('data.items.0.quantity', 2)
        ->assertJsonPath('data.items.0.unit_price', 150_000);

    expect($response->json('data.id'))->toBeString()->toHaveLength(36);
});

it('shows an order of the calling merchant', function (): void {
    $id = $this->postJson('/api/orders', orderPayload(), ['X-Api-Key' => $this->key])->json('data.id');

    $this->getJson("/api/orders/{$id}", ['X-Api-Key' => $this->key])
        ->assertOk()
        ->assertJsonPath('data.id', $id)
        ->assertJsonPath('data.total_amount', 500_000);
});

it('hides orders of other merchants', function (): void {
    $id = $this->postJson('/api/orders', orderPayload(), ['X-Api-Key' => $this->key])->json('data.id');
    [, $otherKey] = merchantWithKey('Other Shop');

    $this->getJson("/api/orders/{$id}", ['X-Api-Key' => $otherKey])
        ->assertNotFound()
        ->assertExactJson(['error' => ['code' => 'not_found', 'message' => 'Order not found.']]);
});

it('requires a valid API key', function (array $headers): void {
    $this->postJson('/api/orders', orderPayload(), $headers)
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthenticated');
})->with([
    'no key' => [[]],
    'unknown key' => [['X-Api-Key' => 'nope']],
]);

it('validates the order', function (array $payload, string $field): void {
    $this->postJson('/api/orders', $payload, ['X-Api-Key' => $this->key])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['code', 'message', 'details' => [$field]]]);
})->with([
    'no items' => [orderPayload(['items' => []]), 'items'],
    'zero quantity' => [orderPayload(['items' => [['sku' => 'A', 'name' => 'A', 'quantity' => 0, 'unit_price' => 100]]]), 'items.0.quantity'],
    'float price' => [orderPayload(['items' => [['sku' => 'A', 'name' => 'A', 'quantity' => 1, 'unit_price' => 10.5]]]), 'items.0.unit_price'],
    'negative price' => [orderPayload(['items' => [['sku' => 'A', 'name' => 'A', 'quantity' => 1, 'unit_price' => -1]]]), 'items.0.unit_price'],
    'other currency' => [orderPayload(['currency' => 'USD']), 'currency'],
]);

it('answers unknown routes in the API error format', function (): void {
    $this->getJson('/api/nowhere', ['X-Api-Key' => $this->key])
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');
});

it('seeds the demo merchant with the configured key', function (): void {
    $this->seed();

    $this->postJson('/api/orders', orderPayload(), ['X-Api-Key' => 'demo-store-key'])->assertCreated();
});
