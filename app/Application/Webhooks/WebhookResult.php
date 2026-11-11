<?php

declare(strict_types=1);

namespace App\Application\Webhooks;

enum WebhookResult: string
{
    case Processed = 'processed';
    /** The same event id came again: nothing changed. */
    case Duplicate = 'duplicate';
    /** No such payment, or a status this service does not act on. */
    case Ignored = 'ignored';
}
