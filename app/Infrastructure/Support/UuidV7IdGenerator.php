<?php

declare(strict_types=1);

namespace App\Infrastructure\Support;

use App\Application\Ports\IdGenerator;
use Illuminate\Support\Str;

final class UuidV7IdGenerator implements IdGenerator
{
    public function next(): string
    {
        return Str::uuid7()->toString();
    }
}
