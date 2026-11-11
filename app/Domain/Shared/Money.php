<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use InvalidArgumentException;
use LogicException;
use Stringable;

/**
 * A non-negative amount in minor units (tiyn for KZT). Never a float.
 */
final readonly class Money implements Stringable
{
    private function __construct(
        public int $amount,
        public Currency $currency,
    ) {}

    public static function of(int $amount, Currency $currency): self
    {
        if ($amount < 0) {
            throw new InvalidArgumentException("Money cannot be negative, got {$amount}.");
        }

        return new self($amount, $currency);
    }

    public static function zero(Currency $currency): self
    {
        return new self(0, $currency);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount + $other->amount, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return self::of($this->amount - $other->amount, $this->currency);
    }

    public function times(int $quantity): self
    {
        if ($quantity < 0) {
            throw new InvalidArgumentException("Quantity cannot be negative, got {$quantity}.");
        }

        return new self($this->amount * $quantity, $this->currency);
    }

    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amount > $other->amount;
    }

    public function equals(self $other): bool
    {
        return $this->currency->equals($other->currency) && $this->amount === $other->amount;
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    public function __toString(): string
    {
        $units = $this->currency->minorUnits();
        $divisor = 10 ** $units;

        return sprintf('%d.%0'.$units.'d %s', intdiv($this->amount, $divisor), $this->amount % $divisor, $this->currency->code);
    }

    private function assertSameCurrency(self $other): void
    {
        if (! $this->currency->equals($other->currency)) {
            throw new LogicException("Cannot combine {$this->currency->code} with {$other->currency->code}.");
        }
    }
}
