<?php

declare(strict_types=1);

namespace App\Infrastructure\Demo;

final readonly class VerifyCheck
{
    public function __construct(
        public string $name,
        public bool $passed,
        public int|string $value,
    ) {}
}
