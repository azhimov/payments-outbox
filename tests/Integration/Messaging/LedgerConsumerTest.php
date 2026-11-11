<?php

declare(strict_types=1);

use App\Application\Ports\PaymentGateway;
use App\Infrastructure\Messaging\LedgerConsumer;
use App\Infrastructure\Messaging\RabbitMqConnector;
use Illuminate\Support\Facades\DB;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Symfony\Component\Process\Process;
use Tests\Doubles\ScriptedGateway;

beforeEach(function (): void {
    if (getenv('RABBITMQ_TESTS') !== '1') {
        $this->markTestSkipped('set RABBITMQ_TESTS=1 with a running RabbitMQ');
    }

    $this->artisan('rabbitmq:setup')->assertSuccessful();
    $this->amqp = app(RabbitMqConnector::class)->connect();
    $this->channel = $this->amqp->channel();
    $this->channel->queue_purge('ledger');
    $this->channel->queue_purge('ledger.dlq');
    config(['outbox.transport' => 'rabbitmq']);
    app()->instance(PaymentGateway::class, new ScriptedGateway);
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

function paidThroughRelay(): string
{
    [, $key] = merchantWithKey();
    $paymentId = pay($key, createOrder($key), 'key-'.uniqid())->assertCreated()->json('data.id');
    test()->artisan('outbox:relay', ['--once' => true])->assertSuccessful();

    return $paymentId;
}

function consumeLedger(int $max): int
{
    return app(LedgerConsumer::class)->run(10, $max, fn (): bool => false);
}

function queueDepth(string $queue): int
{
    [, $count] = test()->channel->queue_declare($queue, true);

    return $count;
}

it('books a paid order from RabbitMQ and acks it', function (): void {
    $paymentId = paidThroughRelay();

    expect(consumeLedger(1))->toBe(1)
        ->and(DB::table('ledger_entries')->where('payment_id', $paymentId)->count())->toBe(2)
        ->and(queueDepth('ledger'))->toBe(0);
});

it('books a message delivered twice only once', function (): void {
    $paymentId = paidThroughRelay();
    // At-least-once: the relay may send the same message again after a lost lease.
    DB::table('outbox_messages')->update(['status' => 'pending', 'available_at' => 0]);
    $this->artisan('outbox:relay', ['--once' => true])->assertSuccessful();

    expect(consumeLedger(2))->toBe(2)
        ->and(DB::table('ledger_entries')->where('payment_id', $paymentId)->count())->toBe(2)
        ->and(DB::table('inbox_messages')->where('consumer', 'ledger')->count())->toBe(1);
});

it('dead-letters a message it cannot book, and dlq-retry puts it back', function (): void {
    $this->channel->basic_publish(new AMQPMessage('not json', ['application_headers' => new AMQPTable(['ce_id' => 'x'])]), 'payments', 'payment.succeeded');

    consumeLedger(1);
    expect(queueDepth('ledger.dlq'))->toBe(1)
        ->and(DB::table('ledger_entries')->count())->toBe(0);

    $this->artisan('ledger:dlq-retry')->expectsOutputToContain('moved 1 message(s)')->assertSuccessful();
    expect(queueDepth('ledger.dlq'))->toBe(0)
        ->and(queueDepth('ledger'))->toBe(1);
});

it('brings a fixed message home: dead-lettered, retried, booked', function (): void {
    $paymentId = paidThroughRelay();
    // Simulate a failure on first delivery (say the database was down): reject it.
    $message = $this->channel->basic_get('ledger');
    $message->nack(false);
    for ($i = 0; $i < 50 && queueDepth('ledger.dlq') === 0; $i++) {
        usleep(20_000);
    }

    $this->artisan('ledger:dlq-retry')->assertSuccessful();
    consumeLedger(1);

    expect(DB::table('ledger_entries')->where('payment_id', $paymentId)->count())->toBe(2);
});

it('stops when asked between messages', function (): void {
    expect(app(LedgerConsumer::class)->run(10, null, fn (): bool => true))->toBe(0);
});

it('exits cleanly on SIGTERM', function (): void {
    // Laravel registers no signal handlers when APP_ENV=testing (runningUnitTests), so run it as a real process.
    $process = new Process(['php', 'artisan', 'ledger:consume'], base_path(), [...getenv(), 'APP_ENV' => 'local'], timeout: 30);
    $process->start();
    $process->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'consuming'));
    usleep(300_000);

    $process->signal(SIGTERM);
    $process->wait();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())->toContain('stopped after 0 message(s)');
})->skip(fn (): bool => getenv('DB_CONNECTION') !== 'pgsql' || ! function_exists('pcntl_signal'), 'needs PostgreSQL and pcntl');
