<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * Declares exchanges, queues and bindings. Every declaration is idempotent, so running
 * rabbitmq:setup again (every `docker compose up`) changes nothing.
 *
 *   payments (topic) --payment.succeeded / payment.refunded--> ledger
 *   ledger --reject--> payments.dlx (fanout) --> ledger.dlq
 */
final class RabbitMqTopology
{
    public function declare(AMQPChannel $channel): void
    {
        $exchange = config()->string('rabbitmq.exchange');
        $queue = config()->string('rabbitmq.ledger.queue');
        $dlx = config()->string('rabbitmq.ledger.dead_letter_exchange');
        $dlq = config()->string('rabbitmq.ledger.dead_letter_queue');

        // durable, not auto-deleted: the same flags the outbox transport uses for its exchange.
        $channel->exchange_declare($exchange, 'topic', false, true, false);
        $channel->exchange_declare($dlx, 'fanout', false, true, false);

        $channel->queue_declare($dlq, false, true, false, false);
        $channel->queue_bind($dlq, $dlx);

        $channel->queue_declare($queue, false, true, false, false, false, new AMQPTable([
            'x-dead-letter-exchange' => $dlx,
        ]));

        foreach (config()->array('rabbitmq.ledger.routing_keys') as $routingKey) {
            $channel->queue_bind($queue, $exchange, is_string($routingKey) ? $routingKey : '');
        }
    }
}
