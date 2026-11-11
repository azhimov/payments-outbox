<?php

declare(strict_types=1);

use App\Domain\Shared\Currency;
use App\Domain\Shared\Money;

it('holds an amount in minor units and a currency', function (): void {
    $money = Money::of(150_000, Currency::KZT());

    expect($money->amount)->toBe(150_000)
        ->and($money->currency->equals(Currency::KZT()))->toBeTrue()
        ->and((string) $money)->toBe('1500.00 KZT');
});

it('refuses negative amounts', function (): void {
    Money::of(-1, Currency::KZT());
})->throws(InvalidArgumentException::class);

it('adds and subtracts in the same currency', function (): void {
    $a = Money::of(1_000, Currency::KZT());
    $b = Money::of(250, Currency::KZT());

    expect($a->add($b)->amount)->toBe(1_250)
        ->and($a->subtract($b)->amount)->toBe(750)
        ->and($a->subtract($a)->isZero())->toBeTrue();
});

it('cannot go below zero', function (): void {
    Money::of(100, Currency::KZT())->subtract(Money::of(101, Currency::KZT()));
})->throws(InvalidArgumentException::class);

it('multiplies by a quantity', function (): void {
    expect(Money::of(2_500, Currency::KZT())->times(3)->amount)->toBe(7_500);
});

it('refuses a negative quantity', function (): void {
    Money::of(2_500, Currency::KZT())->times(-1);
})->throws(InvalidArgumentException::class);

it('compares amounts', function (): void {
    $small = Money::of(100, Currency::KZT());
    $big = Money::of(200, Currency::KZT());

    expect($big->greaterThan($small))->toBeTrue()
        ->and($small->greaterThan($big))->toBeFalse()
        ->and($small->equals(Money::of(100, Currency::KZT())))->toBeTrue()
        ->and(Money::zero(Currency::KZT())->isZero())->toBeTrue();
});

it('knows how many minor units a currency has', function (): void {
    expect(Currency::KZT()->minorUnits())->toBe(2)
        ->and(Currency::from('kzt')->equals(Currency::KZT()))->toBeTrue();
});

it('accepts only supported currencies', function (): void {
    Currency::from('USD');
})->throws(InvalidArgumentException::class, 'Unsupported currency [USD]');
