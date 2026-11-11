<?php

declare(strict_types=1);

namespace App\Presentation\Console;

use App\Application\Reconcile\ReconcilePayments;
use Illuminate\Console\Command;

final class ReconcilePaymentsCommand extends Command
{
    protected $signature = 'payments:reconcile
        {--older-than= : Seconds a payment must have been processing (default: services.psp.reconcile_after)}
        {--limit=100 : Payments per run}';

    protected $description = 'Ask the PSP about payments stuck in processing and finish them';

    public function handle(ReconcilePayments $reconcile): int
    {
        $olderThan = $this->option('older-than');
        $limit = $this->option('limit');

        $result = $reconcile->handle(
            is_numeric($olderThan) ? (int) $olderThan : config()->integer('services.psp.reconcile_after'),
            is_numeric($limit) ? (int) $limit : 100,
        );

        $this->info("reconcile: checked {$result->checked}, finalized {$result->finalized}, still processing {$result->stillProcessing}");

        return self::SUCCESS;
    }
}
