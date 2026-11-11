<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;

/**
 * The application's Prometheus metrics. Counters and histograms are written as things
 * happen; gauges that describe state (outbox lag, ledger balance) are set at scrape time.
 */
final readonly class Metrics
{
    private const LATENCY_BUCKETS = [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0];

    public function __construct(
        private CollectorRegistry $registry,
        private string $namespace,
    ) {}

    public function httpRequest(string $method, string $route, int $status, float $seconds): void
    {
        $labels = [$method, $route, (string) $status];
        $this->registry->getOrRegisterCounter($this->namespace, 'http_requests_total', 'API requests', ['method', 'route', 'status'])->inc($labels);
        $this->registry->getOrRegisterHistogram($this->namespace, 'http_request_duration_seconds', 'API latency', ['method', 'route', 'status'], self::LATENCY_BUCKETS)->observe($seconds, $labels);
    }

    /** stored | replayed | conflict | mismatch */
    public function idempotency(string $outcome): void
    {
        $this->registry->getOrRegisterCounter($this->namespace, 'idempotency_total', 'Idempotency-Key outcomes', ['outcome'])->inc([$outcome]);
    }

    /** stored | published | publish_failed | dead_lettered | lease_lost */
    public function outbox(string $event): void
    {
        $this->registry->getOrRegisterCounter($this->namespace, 'outbox_events_total', 'Outbox lifecycle', ['event'])->inc([$event]);
    }

    /** posted | duplicate | ignored | dead_lettered */
    public function ledger(string $outcome): void
    {
        $this->registry->getOrRegisterCounter($this->namespace, 'ledger_messages_total', 'Ledger consumer outcomes', ['outcome'])->inc([$outcome]);
    }

    public function inboxDuplicate(string $consumer): void
    {
        $this->registry->getOrRegisterCounter($this->namespace, 'inbox_duplicates_total', 'Redeliveries skipped by the inbox', ['consumer'])->inc([$consumer]);
    }

    public function psp(string $operation, string $outcome, float $seconds): void
    {
        $this->registry->getOrRegisterCounter($this->namespace, 'psp_calls_total', 'PSP calls by outcome', ['operation', 'outcome'])->inc([$operation, $outcome]);
        $this->registry->getOrRegisterHistogram($this->namespace, 'psp_call_duration_seconds', 'PSP latency', ['operation'], self::LATENCY_BUCKETS)->observe($seconds, [$operation]);
    }

    /**
     * @param  list<string>  $labelNames
     * @param  list<string>  $labels
     */
    public function gauge(string $name, string $help, float $value, array $labelNames = [], array $labels = []): void
    {
        $this->registry->getOrRegisterGauge($this->namespace, $name, $help, $labelNames)->set($value, $labels);
    }

    public function render(): string
    {
        return (new RenderTextFormat)->render($this->registry->getMetricFamilySamples());
    }
}
