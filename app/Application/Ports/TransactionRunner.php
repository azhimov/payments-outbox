<?php

declare(strict_types=1);

namespace App\Application\Ports;

use Closure;

interface TransactionRunner
{
    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public function run(Closure $work): mixed;
}
