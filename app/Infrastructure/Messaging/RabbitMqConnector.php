<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Connection\AMQPStreamConnection;

final class RabbitMqConnector
{
    public function connect(): AbstractConnection
    {
        return new AMQPStreamConnection(
            host: config()->string('rabbitmq.connection.host'),
            port: config()->integer('rabbitmq.connection.port'),
            user: config()->string('rabbitmq.connection.user'),
            password: config()->string('rabbitmq.connection.password'),
            vhost: config()->string('rabbitmq.connection.vhost'),
        );
    }
}
