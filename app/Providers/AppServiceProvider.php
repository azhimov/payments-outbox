<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Payments\CreatePayment;
use App\Application\Ports\Clock;
use App\Application\Ports\Deduplicator;
use App\Application\Ports\IdGenerator;
use App\Application\Ports\IntegrationEvents;
use App\Application\Ports\LedgerRepository;
use App\Application\Ports\MerchantDirectory;
use App\Application\Ports\OrderRepository;
use App\Application\Ports\PaymentGateway;
use App\Application\Ports\PaymentRepository;
use App\Application\Ports\RefundRepository;
use App\Application\Ports\TransactionRunner;
use App\Infrastructure\Messaging\InboxDeduplicator;
use App\Infrastructure\Messaging\OutboxIntegrationEvents;
use App\Infrastructure\Persistence\DatabaseLedgerRepository;
use App\Infrastructure\Persistence\DatabaseRefundRepository;
use App\Infrastructure\Persistence\EloquentMerchantDirectory;
use App\Infrastructure\Persistence\EloquentOrderRepository;
use App\Infrastructure\Persistence\EloquentPaymentRepository;
use App\Infrastructure\Psp\HttpPaymentGateway;
use App\Infrastructure\Psp\PspSignature;
use App\Infrastructure\Support\LaravelTransactionRunner;
use App\Infrastructure\Support\SystemClock;
use App\Infrastructure\Support\UuidV7IdGenerator;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $singletons = [
        IdGenerator::class => UuidV7IdGenerator::class,
        MerchantDirectory::class => EloquentMerchantDirectory::class,
        OrderRepository::class => EloquentOrderRepository::class,
        PaymentRepository::class => EloquentPaymentRepository::class,
        IntegrationEvents::class => OutboxIntegrationEvents::class,
        Deduplicator::class => InboxDeduplicator::class,
        Clock::class => SystemClock::class,
        LedgerRepository::class => DatabaseLedgerRepository::class,
        RefundRepository::class => DatabaseRefundRepository::class,
    ];

    public function register(): void
    {
        $this->app->bind(PspSignature::class, fn (): PspSignature => new PspSignature(config()->string('services.psp.webhook_secret')));

        $this->app->when(CreatePayment::class)->needs('$webhookUrl')->give(fn (): string => config()->string('services.psp.webhook_url'));

        $this->app->singleton(TransactionRunner::class, fn (Application $app): TransactionRunner => new LaravelTransactionRunner(
            $app->make('db')->connection(),
        ));

        $this->app->singleton(PaymentGateway::class, fn (Application $app): PaymentGateway => new HttpPaymentGateway(
            http: $app->make(Http::class),
            baseUrl: config()->string('services.psp.base_url'),
            timeout: config()->float('services.psp.timeout'),
            connectTimeout: config()->float('services.psp.connect_timeout'),
        ));
    }
}
