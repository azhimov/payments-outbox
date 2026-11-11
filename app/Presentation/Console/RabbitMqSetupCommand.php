<?php

declare(strict_types=1);

namespace App\Presentation\Console;

use App\Infrastructure\Messaging\RabbitMqConnector;
use App\Infrastructure\Messaging\RabbitMqTopology;
use Illuminate\Console\Command;

final class RabbitMqSetupCommand extends Command
{
    protected $signature = 'rabbitmq:setup';

    protected $description = 'Declare the payments exchange, the ledger queue and its dead-letter queue';

    public function handle(RabbitMqConnector $connector, RabbitMqTopology $topology): int
    {
        $connection = $connector->connect();
        $channel = $connection->channel();

        $topology->declare($channel);

        $channel->close();
        $connection->close();

        $this->info('RabbitMQ topology is in place.');

        return self::SUCCESS;
    }
}
