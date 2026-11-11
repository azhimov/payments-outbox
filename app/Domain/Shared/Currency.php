<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use InvalidArgumentException;

/**
 * An ISO 4217 currency the service accepts. Only KZT today; a value object rather than
 * an enum so that currency checks stay meaningful when a second currency is added.
 */
final readonly class Currency
{
    /** Code => digits after the decimal point. */
    private const SUPPORTED = ['KZT' => 2];

    private function __construct(public string $code) {}

    public static function from(string $code): self
    {
        $code = strtoupper($code);

        if (! array_key_exists($code, self::SUPPORTED)) {
            throw new InvalidArgumentException("Unsupported currency [{$code}].");
        }

        return new self($code);
    }

    public static function KZT(): self
    {
        return self::from('KZT');
    }

    public function minorUnits(): int
    {
        return self::SUPPORTED[$this->code];
    }

    public function equals(self $other): bool
    {
        return $this->code === $other->code;
    }
}
