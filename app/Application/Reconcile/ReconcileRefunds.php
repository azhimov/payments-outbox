<?php

declare(strict_types=1);

namespace App\Application\Reconcile;

use App\Application\Ports\Clock;
use App\Application\Ports\PaymentGateway;
use App\Application\Ports\RefundRepository;
use App\Application\Refunds\RefundOutcomes;

/**
 * Finishes refunds left processing after an unclear PSP answer that the client never
 * retried: asks the PSP and applies the answer through RefundOutcomes.
 */
final readonly class ReconcileRefunds
{
    public function __construct(
        private RefundRepository $refunds,
        private PaymentGateway $gateway,
        private RefundOutcomes $outcomes,
        private Clock $clock,
    ) {}

    public function handle(int $olderThanSeconds, int $limit = 100): ReconcileResult
    {
        $ids = $this->refunds->processingSince($this->clock->now()->modify("-{$olderThanSeconds} seconds"), $limit);
        $finalized = 0;

        foreach ($ids as $id) {
            if ($this->outcomes->apply($id, $this->gateway->refundStatus($id))->status !== 'processing') {
                $finalized++;
            }
        }

        return new ReconcileResult(count($ids), $finalized, count($ids) - $finalized);
    }
}
