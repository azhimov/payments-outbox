<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use App\Application\Ports\IntegrationEvent;
use App\Application\Ports\IntegrationEvents;
use Azhimov\Outbox\Contracts\Outbox;

/**
 * Integration events go through laravel-outbox: stored in the caller's transaction,
 * delivered to RabbitMQ by outbox:relay.
 */
final readonly class OutboxIntegrationEvents implements IntegrationEvents
{
    public function __construct(private Outbox $outbox) {}

    public function publish(IntegrationEvent $event): void
    {
        $this->outbox->publish(
            topic: $event->topic,
            type: $event->type,
            payload: $event->payload,
            aggregateId: $event->aggregateId,
        );
    }
}
