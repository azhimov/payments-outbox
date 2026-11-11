<?php

declare(strict_types=1);

namespace App\Application\Ledger;

/** An event as the ledger consumer receives it from the broker. */
final readonly class IncomingEvent
{
    /**
     * @param  array<array-key, mixed>  $payload
     */
    public function __construct(
        public string $id,
        public string $type,
        public array $payload,
    ) {}
}
