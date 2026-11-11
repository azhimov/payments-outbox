<?php

declare(strict_types=1);

use App\Infrastructure\Persistence\Models\MerchantModel;
use Illuminate\Support\Str;

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
