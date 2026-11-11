<?php

declare(strict_types=1);

namespace App\Presentation\Console;

use App\Infrastructure\Messaging\RabbitMqConnector;
use Illuminate\Console\Command;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

final class LedgerDlqRetryCommand extends Command
{
    protected $signature = 'ledger:dlq-retry {--limit=100 : Messages to move back}';

    protected $description = 'Move dead-lettered ledger messages back to the payments exchange (after fixing the cause)';

    public function handle(RabbitMqConnector $connector): int
    {
        $connection = $connector->connect();
        $channel = $connection->channel();
        $channel->confirm_select();
        $limit = is_numeric($this->option('limit')) ? (int) $this->option('limit') : 100;
        $moved = 0;

        while ($moved < $limit && ($message = $channel->basic_get(config()->string('rabbitmq.ledger.dead_letter_queue'))) instanceof AMQPMessage) {
            $properties = $message->get_properties();

            // Keep the CloudEvents headers, drop the broker's x-death bookkeeping.
            if (($properties['application_headers'] ?? null) instanceof AMQPTable) {
                $headers = $properties['application_headers']->getNativeData();
                unset($headers['x-death'], $headers['x-first-death-exchange'], $headers['x-first-death-queue'], $headers['x-first-death-reason'], $headers['x-last-death-exchange'], $headers['x-last-death-queue'], $headers['x-last-death-reason']);
                $properties['application_headers'] = new AMQPTable($headers);
            }

            $channel->basic_publish(new AMQPMessage($message->getBody(), $properties), config()->string('rabbitmq.exchange'), $message->getRoutingKey() ?? '');
            $channel->wait_for_pending_acks(5.0);
            $message->ack();
            $moved++;
        }

        $channel->close();
        $connection->close();
        $this->info("ledger: moved {$moved} message(s) back from the DLQ");

        return self::SUCCESS;
    }
}
