<?php

declare(strict_types=1);

namespace App\Infrastructure\Support;

use App\Application\Ports\TransactionRunner;
use Closure;
use Illuminate\Database\ConnectionInterface;

final readonly class LaravelTransactionRunner implements TransactionRunner
{
    public function __construct(private ConnectionInterface $connection) {}

    public function run(Closure $work): mixed
    {
        return $this->connection->transaction($work);
    }
}
