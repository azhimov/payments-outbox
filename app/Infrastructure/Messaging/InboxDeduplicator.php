<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use App\Application\Ports\Deduplicator;
use Azhimov\Outbox\Inbox\Inbox;
use Azhimov\Outbox\Inbox\InboxOutcome;
use Closure;

/** laravel-outbox's inbox: the mark and $work commit in one transaction. */
final readonly class InboxDeduplicator implements Deduplicator
{
    public function __construct(private Inbox $inbox) {}

    public function once(string $consumer, string $messageId, Closure $work): bool
    {
        return $this->inbox->handle($consumer, $messageId, $work) === InboxOutcome::Processed;
    }
}
