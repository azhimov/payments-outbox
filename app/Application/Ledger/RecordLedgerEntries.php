<?php

declare(strict_types=1);

namespace App\Application\Ledger;

use App\Application\Ports\Deduplicator;
use App\Application\Ports\LedgerRepository;
use App\Domain\Ledger\LedgerTransaction;
use App\Domain\Shared\Currency;
use App\Domain\Shared\Money;
use InvalidArgumentException;

/**
 * Books payment.succeeded / payment.refunded once per event id: the inbox mark and the
 * ledger entries commit in one transaction, so a redelivery cannot book twice.
 */
final readonly class RecordLedgerEntries
{
    public const CONSUMER = 'ledger';

    public function __construct(
        private Deduplicator $inbox,
        private LedgerRepository $ledger,
    ) {}

    public function handle(IncomingEvent $event): LedgerOutcome
    {
        $factory = match ($event->type) {
            'payment.succeeded' => LedgerTransaction::paymentCaptured(...),
            'payment.refunded' => LedgerTransaction::paymentRefunded(...),
            default => null,
        };

        if ($factory === null) {
            return LedgerOutcome::Ignored;
        }

        $amount = $this->amount($event);
        $paymentId = $event->payload['payment_id'] ?? null;

        if (! is_string($paymentId) || $paymentId === '') {
            throw new InvalidLedgerEvent("Event {$event->id} has no payment_id.");
        }

        $posted = $this->inbox->once(self::CONSUMER, $event->id, fn () => $this->ledger->record($factory($event->id, $amount), $event->type, $paymentId));

        return $posted ? LedgerOutcome::Posted : LedgerOutcome::Duplicate;
    }

    private function amount(IncomingEvent $event): Money
    {
        $amount = $event->payload['amount'] ?? null;
        $currency = $event->payload['currency'] ?? null;

        if (! is_int($amount) || ! is_string($currency)) {
            throw new InvalidLedgerEvent("Event {$event->id} has no integer amount and currency.");
        }

        try {
            return Money::of($amount, Currency::from($currency));
        } catch (InvalidArgumentException $e) {
            throw new InvalidLedgerEvent("Event {$event->id}: {$e->getMessage()}", 0, $e);
        }
    }
}
