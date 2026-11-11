<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Ports\IdGenerator;
use App\Application\Ports\MerchantDirectory;
use App\Application\Ports\OrderRepository;
use App\Application\Ports\PaymentGateway;
use App\Infrastructure\Persistence\EloquentMerchantDirectory;
use App\Infrastructure\Persistence\EloquentOrderRepository;
use App\Infrastructure\Psp\HttpPaymentGateway;
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
    ];

    public function register(): void
    {
        $this->app->singleton(PaymentGateway::class, fn (Application $app): PaymentGateway => new HttpPaymentGateway(
            http: $app->make(Http::class),
            baseUrl: config()->string('services.psp.base_url'),
            timeout: config()->float('services.psp.timeout'),
            connectTimeout: config()->float('services.psp.connect_timeout'),
        ));
    }
}
