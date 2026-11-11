<?php

declare(strict_types=1);

namespace App\Application\Webhooks;

final readonly class PspWebhook
{
    public function __construct(
        /** The PSP's event id: the same event sent twice has the same id. */
        public string $eventId,
        public string $paymentId,
        public string $status,
        public ?string $pspPaymentId,
        public ?string $declineReason,
    ) {}
}
