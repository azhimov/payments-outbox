<?php

declare(strict_types=1);

namespace App\Application\Reconcile;

use App\Application\Payments\PaymentOutcomes;
use App\Application\Ports\Clock;
use App\Application\Ports\PaymentGateway;
use App\Application\Ports\PaymentRepository;
use App\Domain\Payment\PaymentStatus;

/**
 * Closes payments stuck in processing (timeout at charge time, lost webhook, crash
 * between the charge and its transaction) by asking the PSP, through the same
 * PaymentOutcomes the webhook uses. Whoever comes second is a no-op.
 */
final readonly class ReconcilePayments
{
    public function __construct(
        private PaymentRepository $payments,
        private PaymentGateway $gateway,
        private PaymentOutcomes $outcomes,
        private Clock $clock,
    ) {}

    public function handle(int $olderThanSeconds, int $limit = 100): ReconcileResult
    {
        $before = $this->clock->now()->modify("-{$olderThanSeconds} seconds");
        $ids = $this->payments->processingSince($before, $limit);
        $finalized = 0;

        foreach ($ids as $id) {
            $payment = $this->outcomes->apply($id, $this->gateway->status($id));

            if ($payment->status() !== PaymentStatus::Processing) {
                $finalized++;
            }
        }

        return new ReconcileResult(count($ids), $finalized, count($ids) - $finalized);
    }
}
