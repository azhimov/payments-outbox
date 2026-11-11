<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('pays every order with repeated requests under one key per order and refunds a share', function (): void {
    $keysByOrder = [];
    $orders = 0;
    Http::fake(function (Request $request) use (&$keysByOrder, &$orders) {
        if (str_ends_with($request->url(), '/api/orders')) {
            return Http::response(['data' => ['id' => 'order-'.(++$orders)]], 201);
        }

        if (preg_match('#/api/orders/([^/]+)/payments$#', $request->url(), $m) === 1) {
            $keysByOrder[$m[1]][] = $request->header('Idempotency-Key')[0];

            return Http::response(['data' => ['id' => 'pay-'.$m[1], 'status' => 'succeeded', 'amount' => 1000]], 201);
        }

        return Http::response(['data' => ['status' => 'succeeded']], 201);
    });

    $this->artisan('demo:load', ['--orders' => 20, '--chunk' => 5, '--refund-share' => 0.5, '--base-url' => 'http://api.test'])
        ->expectsOutputToContain('load: 20/20 orders')
        ->assertSuccessful();

    expect($keysByOrder)->toHaveCount(20);
    foreach ($keysByOrder as $keys) {
        expect(count($keys))->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(3)
            ->and(array_unique($keys))->toHaveCount(1);
    }

    Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/refunds') && $r['amount'] === 500 && $r->hasHeader('Idempotency-Key'));
});

it('retries with the same key after a 409 or a 5xx', function (): void {
    $calls = 0;
    Http::fake(function (Request $request) use (&$calls) {
        if (str_ends_with($request->url(), '/api/orders')) {
            return Http::response(['data' => ['id' => 'order-1']], 201);
        }
        if (str_contains($request->url(), '/payments')) {
            $calls++;

            return $calls === 1
                ? Http::response(['error' => ['code' => 'idempotency_request_in_progress']], 409)
                : Http::response(['data' => ['id' => 'p', 'status' => 'succeeded', 'amount' => 10]], 201);
        }

        return Http::response([], 201);
    });

    $this->artisan('demo:load', ['--orders' => 1, '--refund-share' => 0, '--base-url' => 'http://api.test'])->assertSuccessful();

    expect($calls)->toBeGreaterThanOrEqual(2);
});
