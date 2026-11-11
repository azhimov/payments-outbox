<?php

declare(strict_types=1);

namespace App\Application\Ports;

interface IdGenerator
{
    /** A new time-ordered unique id (UUIDv7). */
    public function next(): string;
}
