<?php

declare(strict_types=1);

namespace App\Presentation\Console;

use App\Infrastructure\Messaging\LedgerConsumer;
use Illuminate\Console\Command;

final class LedgerConsumeCommand extends Command
{
    protected $signature = 'ledger:consume
        {--max-messages= : Stop after this many messages (tests, one-off runs)}
        {--prefetch= : Unacked messages per consumer (default: rabbitmq.ledger.prefetch)}';

    protected $description = 'Book payment events from RabbitMQ into the ledger';

    private bool $stopping = false;

    public function handle(LedgerConsumer $consumer): int
    {
        // SIGTERM / SIGINT: finish (and ack) the message in hand, then stop.
        $this->trap([SIGTERM, SIGINT], function (): void {
            $this->stopping = true;
        });

        $max = $this->option('max-messages');
        $prefetch = $this->option('prefetch');

        $this->line('ledger: consuming');
        $handled = $consumer->run(
            is_numeric($prefetch) ? (int) $prefetch : config()->integer('rabbitmq.ledger.prefetch'),
            is_numeric($max) ? (int) $max : null,
            fn (): bool => $this->stopping,
        );
        $this->line("ledger: stopped after {$handled} message(s)");

        return self::SUCCESS;
    }
}
