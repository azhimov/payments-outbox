<?php

declare(strict_types=1);

namespace App\Presentation\Console\Demo;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;

/**
 * Plays a nervous client: creates orders and pays each with 1-3 identical requests
 * (same Idempotency-Key), part of them in parallel, retrying 409 and 5xx with the same key.
 * Then refunds part of the paid orders, also with repeats.
 */
final class LoadCommand extends Command
{
    protected $signature = 'demo:load
        {--orders=500 : Orders to create and pay}
        {--chunk=10 : Orders handled concurrently}
        {--refund-share=0.1 : Share of paid orders refunded (half their amount)}
        {--base-url= : API base URL (default: config demo.base_url)}';

    protected $description = 'Generate paying traffic with retries against the API';

    /** @var array<string, int> */
    private array $stats = ['orders' => 0, 'payment_requests' => 0, 'refund_requests' => 0];

    /** @var array<string, int> */
    private array $statuses = [];

    private Http $http;

    public function handle(Http $http): int
    {
        $this->http = $http;

        $orders = is_numeric($this->option('orders')) ? (int) $this->option('orders') : 500;
        $chunk = max(1, is_numeric($this->option('chunk')) ? (int) $this->option('chunk') : 10);
        $refundShare = is_numeric($this->option('refund-share')) ? (float) $this->option('refund-share') : 0.1;
        $api = fn (): PendingRequest => $http->baseUrl($this->baseUrl())->acceptJson()->timeout(30)
            ->withHeaders(['X-Api-Key' => config()->string('demo.api_key')]);

        $paid = [];
        for ($done = 0; $done < $orders; $done += $chunk) {
            $paid = [...$paid, ...$this->payChunk($api, min($chunk, $orders - $done))];
            $this->line(sprintf('load: %d/%d orders, %d payment requests', min($done + $chunk, $orders), $orders, $this->stats['payment_requests']));
        }

        $toRefund = array_slice($paid, 0, (int) round(count($paid) * $refundShare));
        foreach ($toRefund as [$paymentId, $amount]) {
            $key = 'refund-'.$paymentId;
            for ($i = 0, $times = random_int(1, 2); $i < $times; $i++) {
                $this->send(fn () => $api()->withHeaders(['Idempotency-Key' => $key])->post("/api/payments/{$paymentId}/refunds", ['amount' => intdiv($amount, 2)]), 'refund_requests');
            }
        }

        $this->table(['metric', 'value'], [
            ...array_map(fn (string $k, int $v): array => [$k, (string) $v], array_keys($this->stats), $this->stats),
            ...array_map(fn (string $k, int $v): array => [$k, (string) $v], array_keys($this->statuses), $this->statuses),
        ]);

        return self::SUCCESS;
    }

    /**
     * @param  \Closure(): PendingRequest  $api
     * @return list<array{0: string, 1: int}> paid [payment id, amount]
     */
    private function payChunk(\Closure $api, int $count): array
    {
        $orderIds = [];
        for ($i = 0; $i < $count; $i++) {
            $order = $this->send(fn () => $api()->post('/api/orders', [
                'currency' => 'KZT',
                'items' => [['sku' => 'SKU-'.random_int(1, 20), 'name' => 'Demo item', 'quantity' => random_int(1, 3), 'unit_price' => random_int(10, 500) * 100]],
            ]), 'orders');
            $id = $order?->json('data.id');
            if (is_string($id)) {
                $orderIds[] = $id;
            }
        }

        // Every order gets 1-3 identical payment requests (one Idempotency-Key): the first and
        // some repeats race in parallel, the other repeats come one after another.
        $concurrent = [];
        $sequential = [];
        foreach ($orderIds as $orderId) {
            $key = 'pay-'.$orderId;
            $concurrent[] = [$orderId, $key];
            for ($i = 1, $times = random_int(1, 3); $i < $times; $i++) {
                random_int(0, 1) === 1 ? $concurrent[] = [$orderId, $key] : $sequential[] = [$orderId, $key];
            }
        }

        $pooled = $this->http->pool(function (Pool $pool) use ($concurrent): array {
            $requests = [];
            foreach ($concurrent as $i => [$orderId, $key]) {
                $requests[] = $this->authorized($pool->as((string) $i), $key)->post("/api/orders/{$orderId}/payments", ['card_token' => 'tok_visa']);
            }

            return $requests;
        });

        $responses = [];
        foreach ($concurrent as $i => $attempt) {
            $response = $pooled[(string) $i] ?? null;
            $responses[] = [$attempt, $response instanceof Response ? $response : null];
        }
        foreach ($sequential as $attempt) {
            $responses[] = [$attempt, $this->attempt(fn (): Response => $this->payRequest($this->http, $attempt[0], $attempt[1]))];
        }

        $paid = [];
        foreach ($responses as [[$orderId, $key], $response]) {
            $this->stats['payment_requests']++;
            $this->count($response);

            // A client that got no final answer retries with the same key.
            if ($response === null || $response->status() === 409 || $response->serverError()) {
                $response = $this->retry(fn (): Response => $this->payRequest($this->http, $orderId, $key));
            }

            $id = $response?->json('data.id');
            $amount = $response?->json('data.amount');
            if ($response?->json('data.status') === 'succeeded' && is_string($id) && is_int($amount)) {
                $paid[$id] = [$id, $amount];
            }
        }

        return array_values($paid);
    }

    private function payRequest(Http $http, string $orderId, string $key): Response
    {
        return $this->authorized($http->baseUrl($this->baseUrl()), $key)->post("/api/orders/{$orderId}/payments", ['card_token' => 'tok_visa']);
    }

    private function authorized(PendingRequest $request, string $key): PendingRequest
    {
        return $request->baseUrl($this->baseUrl())->acceptJson()->timeout(30)
            ->withHeaders(['X-Api-Key' => config()->string('demo.api_key'), 'Idempotency-Key' => $key]);
    }

    /**
     * @param  \Closure(): Response  $request
     */
    private function attempt(\Closure $request): ?Response
    {
        try {
            return $request();
        } catch (ConnectionException) {
            return null;
        }
    }

    /**
     * @param  \Closure(): Response  $request
     */
    private function retry(\Closure $request): ?Response
    {
        for ($try = 0; $try < 5; $try++) {
            usleep(500_000 * ($try + 1));
            $response = $this->send($request, 'payment_requests');

            if ($response !== null && $response->status() !== 409 && ! $response->serverError()) {
                return $response;
            }
        }

        return null;
    }

    /**
     * @param  \Closure(): Response  $request
     */
    private function send(\Closure $request, string $stat): ?Response
    {
        $this->stats[$stat]++;

        try {
            $response = $request();
        } catch (ConnectionException) {
            $response = null;
        }

        $this->count($response);

        return $response;
    }

    private function count(?Response $response): void
    {
        $key = $response === null ? 'http connection error' : 'http '.$response->status();
        $this->statuses[$key] = ($this->statuses[$key] ?? 0) + 1;
    }

    private function baseUrl(): string
    {
        $base = $this->option('base-url');

        return is_string($base) && $base !== '' ? $base : config()->string('demo.base_url');
    }
}
