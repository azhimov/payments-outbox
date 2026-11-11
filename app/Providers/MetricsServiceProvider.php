<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Ports\PaymentGateway;
use App\Infrastructure\Metrics\MeteredPaymentGateway;
use App\Infrastructure\Metrics\Metrics;
use Azhimov\Idempotency\Events\IdempotencyConflict;
use Azhimov\Idempotency\Events\IdempotencyKeyMismatch;
use Azhimov\Idempotency\Events\IdempotentResponseReplayed;
use Azhimov\Idempotency\Events\IdempotentResponseStored;
use Azhimov\Outbox\Events\InboxDuplicateSkipped;
use Azhimov\Outbox\Events\LeaseLost;
use Azhimov\Outbox\Events\MessageFailed;
use Azhimov\Outbox\Events\MessagePublished;
use Azhimov\Outbox\Events\MessagePublishFailed;
use Azhimov\Outbox\Events\MessageStored;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Prometheus\CollectorRegistry;
use Prometheus\Storage\InMemory;
use Prometheus\Storage\Predis;

/**
 * Metrics come from the events both packages dispatch, so the application code that
 * pays and books stays free of instrumentation.
 */
final class MetricsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Metrics::class, function (): Metrics {
            $storage = config()->string('metrics.storage') === 'memory'
                ? new InMemory
                : new Predis([
                    'host' => config()->string('database.redis.default.host'),
                    'port' => (int) config()->string('database.redis.default.port'),
                    'database' => 2,
                ]);

            return new Metrics(new CollectorRegistry($storage, false), config()->string('metrics.namespace'));
        });

        $this->app->extend(PaymentGateway::class, fn (PaymentGateway $gateway, Application $app): PaymentGateway => new MeteredPaymentGateway($gateway, $app->make(Metrics::class)));
    }

    public function boot(): void
    {
        $metrics = fn (): Metrics => $this->app->make(Metrics::class);

        Event::listen(IdempotentResponseStored::class, fn () => $metrics()->idempotency('stored'));
        Event::listen(IdempotentResponseReplayed::class, fn () => $metrics()->idempotency('replayed'));
        Event::listen(IdempotencyConflict::class, fn () => $metrics()->idempotency('conflict'));
        Event::listen(IdempotencyKeyMismatch::class, fn () => $metrics()->idempotency('mismatch'));

        Event::listen(MessageStored::class, fn () => $metrics()->outbox('stored'));
        Event::listen(MessagePublished::class, fn () => $metrics()->outbox('published'));
        Event::listen(MessagePublishFailed::class, fn () => $metrics()->outbox('publish_failed'));
        Event::listen(MessageFailed::class, fn () => $metrics()->outbox('dead_lettered'));
        Event::listen(LeaseLost::class, fn () => $metrics()->outbox('lease_lost'));
        Event::listen(InboxDuplicateSkipped::class, fn (InboxDuplicateSkipped $e) => $metrics()->inboxDuplicate($e->consumer));
    }
}
