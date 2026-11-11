<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use App\Application\Ledger\IncomingEvent;
use App\Application\Ledger\RecordLedgerEntries;
use App\Infrastructure\Metrics\Metrics;
use Azhimov\Outbox\Transports\RabbitMqTransport;
use Closure;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Consumes the ledger queue. ack only after the inbox transaction committed; anything
 * that throws is rejected without requeue, so it lands in ledger.dlq instead of looping.
 */
final readonly class LedgerConsumer
{
    public function __construct(
        private RabbitMqConnector $connector,
        private RecordLedgerEntries $record,
        private LoggerInterface $logger,
        private Metrics $metrics,
    ) {}

    /**
     * @param  Closure(): bool  $shouldStop  checked between messages (SIGTERM)
     * @return int messages handled
     */
    public function run(int $prefetch, ?int $maxMessages, Closure $shouldStop): int
    {
        $connection = $this->connector->connect();
        $channel = $connection->channel();
        $channel->basic_qos(0, $prefetch, false);
        $handled = 0;

        $channel->basic_consume(config()->string('rabbitmq.ledger.queue'), '', false, false, false, false, function (AMQPMessage $message) use (&$handled): void {
            $this->handle($message);
            $handled++;
        });

        while ($channel->is_consuming() && ! $shouldStop() && ($maxMessages === null || $handled < $maxMessages)) {
            try {
                $channel->wait(null, false, 1.0);
            } catch (AMQPTimeoutException) {
                // Idle: loop to check the stop flag.
            }
        }

        $channel->close();
        $connection->close();

        return $handled;
    }

    private function handle(AMQPMessage $message): void
    {
        try {
            $received = RabbitMqTransport::receive($message);
            $outcome = $this->record->handle(new IncomingEvent($received->id, $received->type, $received->payload));
            $message->ack();
            $this->metrics->ledger($outcome->value);

            $this->logger->info('ledger: '.$outcome->value, ['message_id' => $received->id, 'type' => $received->type]);
        } catch (Throwable $e) {
            $message->nack(false);
            $this->metrics->ledger('dead_lettered');
            $this->logger->error('ledger: dead-lettered', ['error' => $e->getMessage(), 'routing_key' => $message->getRoutingKey()]);
        }
    }
}
