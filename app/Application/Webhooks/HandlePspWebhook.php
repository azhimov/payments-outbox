<?php

declare(strict_types=1);

namespace App\Application\Webhooks;

use App\Application\Payments\PaymentOutcomes;
use App\Application\Ports\ChargeOutcome;
use App\Application\Ports\ChargeResult;
use App\Application\Ports\Deduplicator;
use App\Application\Ports\PaymentRepository;
use App\Application\Ports\TransactionRunner;

/**
 * A PSP webhook with the final status of a pending charge. Deduplicated by event id
 * through the inbox, in one transaction with the payment change; PaymentOutcomes makes
 * it a no-op if reconcile (or the API call) got there first.
 */
final readonly class HandlePspWebhook
{
    public const CONSUMER = 'psp-webhooks';

    public function __construct(
        private Deduplicator $inbox,
        private PaymentOutcomes $outcomes,
        private PaymentRepository $payments,
        private TransactionRunner $transaction,
    ) {}

    public function handle(PspWebhook $webhook): WebhookResult
    {
        $result = match ($webhook->status) {
            'succeeded' => new ChargeResult(ChargeOutcome::Succeeded, $webhook->pspPaymentId),
            'declined' => new ChargeResult(ChargeOutcome::Declined, $webhook->pspPaymentId, $webhook->declineReason ?? 'declined'),
            default => null,
        };

        return $this->transaction->run(function () use ($webhook, $result): WebhookResult {
            if ($result === null || $this->payments->findForUpdate($webhook->paymentId) === null) {
                return WebhookResult::Ignored;
            }

            $processed = $this->inbox->once(self::CONSUMER, $webhook->eventId, fn () => $this->outcomes->apply($webhook->paymentId, $result));

            return $processed ? WebhookResult::Processed : WebhookResult::Duplicate;
        });
    }
}
