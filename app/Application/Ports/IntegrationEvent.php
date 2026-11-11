<?php

declare(strict_types=1);

namespace App\Application\Ports;

/**
 * A fact other services learn about through the broker.
 */
final readonly class IntegrationEvent
{
    /**
     * @param  array<string, scalar|null>  $payload
     */
    public function __construct(
        public string $topic,
        public string $type,
        public array $payload,
        public string $aggregateId,
    ) {}
}
