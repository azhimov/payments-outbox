<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Ports\IdGenerator;
use App\Application\Ports\MerchantDirectory;
use App\Application\Ports\OrderRepository;
use App\Infrastructure\Persistence\EloquentMerchantDirectory;
use App\Infrastructure\Persistence\EloquentOrderRepository;
use App\Infrastructure\Support\UuidV7IdGenerator;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $singletons = [
        IdGenerator::class => UuidV7IdGenerator::class,
        MerchantDirectory::class => EloquentMerchantDirectory::class,
        OrderRepository::class => EloquentOrderRepository::class,
    ];
}
