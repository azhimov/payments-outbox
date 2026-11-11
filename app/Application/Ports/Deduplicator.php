<?php

declare(strict_types=1);

namespace App\Application\Ports;

use Closure;

interface Deduplicator
{
    /**
     * Runs $work once per (consumer, messageId), in one transaction with the mark.
     * Returns false when the message was already processed and $work did not run.
     *
     * @param  Closure(): mixed  $work
     */
    public function once(string $consumer, string $messageId, Closure $work): bool;
}
