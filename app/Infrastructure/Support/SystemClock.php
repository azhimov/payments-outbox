<?php

declare(strict_types=1);

namespace App\Infrastructure\Support;

use App\Application\Ports\Clock;
use DateTimeImmutable;
use Illuminate\Support\Facades\Date;

/** Laravel's clock, so tests can freeze and travel in time. */
final class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return Date::now()->toDateTimeImmutable();
    }
}
