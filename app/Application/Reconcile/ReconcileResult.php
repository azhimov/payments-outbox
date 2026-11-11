<?php

declare(strict_types=1);

namespace App\Application\Reconcile;

final readonly class ReconcileResult
{
    public function __construct(
        public int $checked = 0,
        public int $finalized = 0,
        public int $stillProcessing = 0,
    ) {}
}
