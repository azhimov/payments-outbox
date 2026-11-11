<?php

declare(strict_types=1);

namespace App\Presentation\Console;

use App\Application\Reconcile\ReconcileRefunds;
use Illuminate\Console\Command;

final class ReconcileRefundsCommand extends Command
{
    protected $signature = 'refunds:reconcile
        {--older-than= : Seconds a refund must have been processing (default: services.psp.reconcile_after)}
        {--limit=100 : Refunds per run}';

    protected $description = 'Ask the PSP about refunds stuck in processing and finish them';

    public function handle(ReconcileRefunds $reconcile): int
    {
        $olderThan = $this->option('older-than');
        $limit = $this->option('limit');

        $result = $reconcile->handle(
            is_numeric($olderThan) ? (int) $olderThan : config()->integer('services.psp.reconcile_after'),
            is_numeric($limit) ? (int) $limit : 100,
        );

        $this->info("refunds reconcile: checked {$result->checked}, finalized {$result->finalized}, still processing {$result->stillProcessing}");

        return self::SUCCESS;
    }
}
