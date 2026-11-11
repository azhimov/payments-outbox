<?php

declare(strict_types=1);

use App\Application\Ports\PaymentGateway;
use App\Infrastructure\Messaging\RabbitMqConnector;
use PhpAmqpLib\Message\AMQPMessage;
use Tests\Doubles\ScriptedGateway;

/*
| Against a real RabbitMQ (CI service container, or `make up`): RABBITMQ_TESTS=1.
*/

beforeEach(function (): void {
    if (getenv('RABBITMQ_TESTS') !== '1') {
        $this->markTestSkipped('set RABBITMQ_TESTS=1 with a running RabbitMQ');
    }

    $this->artisan('rabbitmq:setup')->assertSuccessful();
    $this->amqp = app(RabbitMqConnector::class)->connect();
    $this->channel = $this->amqp->channel();
    $this->channel->queue_purge('ledger');
    $this->channel->queue_purge('ledger.dlq');
});

afterEach(function (): void {
    if (isset($this->amqp)) {
        // Leave nothing behind for a consumer of a running demo on the same broker.
        $this->channel->queue_purge('ledger');
        $this->channel->queue_purge('ledger.dlq');
        $this->channel->close();
        $this->amqp->close();
    }
});

function getFrom(string $queue): ?AMQPMessage
{
    for ($i = 0; $i < 50; $i++) {
        $message = test()->channel->basic_get($queue);
        if ($message !== null) {
            return $message;
        }
        usleep(20_000);
    }

    return null;
}

it('declares the topology idempotently', function (): void {
    $this->artisan('rabbitmq:setup')->assertSuccessful();

    // Passive declares fail if anything is missing.
    $this->channel->exchange_declare('payments', 'topic', true);
    $this->channel->exchange_declare('payments.dlx', 'fanout', true);
    [, $count] = $this->channel->queue_declare('ledger', true);

    expect($count)->toBe(0);
});

it('routes payment events to the ledger queue and other events nowhere', function (): void {
    $this->channel->basic_publish(new AMQPMessage('{}'), 'payments', 'payment.succeeded');
    $this->channel->basic_publish(new AMQPMessage('{}'), 'payments', 'order.created');

    expect(getFrom('ledger'))->not->toBeNull()
        ->and($this->channel->basic_get('ledger'))->toBeNull();
});

it('dead-letters a rejected ledger message', function (): void {
    $this->channel->basic_publish(new AMQPMessage('{"poison":true}'), 'payments', 'payment.succeeded');
    $message = getFrom('ledger');
    $message->nack(false);

    expect(getFrom('ledger.dlq')?->getBody())->toBe('{"poison":true}');
});

it('delivers a paid order end to end: API, outbox, relay, RabbitMQ', function (): void {
    [, $key] = merchantWithKey();
    app()->instance(PaymentGateway::class, new ScriptedGateway);
    config(['outbox.transport' => 'rabbitmq']);

    $orderId = createOrder($key);
    $paymentId = pay($key, $orderId, 'key-1')->assertCreated()->json('data.id');

    $this->artisan('outbox:relay', ['--once' => true])->assertSuccessful();

    $message = getFrom('ledger');
    $headers = $message->get('application_headers')->getNativeData();

    expect($message->getRoutingKey())->toBe('payment.succeeded')
        ->and(json_decode($message->getBody(), true)['payment_id'])->toBe($paymentId)
        ->and($headers['ce_type'])->toBe('payment.succeeded')
        ->and($headers['ce_subject'])->toBe($orderId);
});
