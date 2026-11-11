<?php

declare(strict_types=1);

use App\Domain\Payment\Exceptions\InvalidPaymentTransition;
use App\Domain\Payment\Exceptions\RefundNotAllowed;
use App\Domain\Payment\Payment;
use App\Domain\Payment\PaymentStatus;
use App\Domain\Shared\Currency;
use App\Domain\Shared\Money;

function newPayment(int $amount = 10_000): Payment
{
    return Payment::create('pay-1', 'order-1', 'merchant-1', Money::of($amount, Currency::KZT()));
}

function paymentIn(PaymentStatus $status): Payment
{
    $payment = newPayment();

    match ($status) {
        PaymentStatus::Created => null,
        PaymentStatus::Processing => $payment->startProcessing(),
        PaymentStatus::Succeeded => [$payment->startProcessing(), $payment->succeed('psp-1')],
        PaymentStatus::Declined => [$payment->startProcessing(), $payment->decline('insufficient_funds')],
        PaymentStatus::Failed => [$payment->startProcessing(), $payment->fail('card_blocked')],
        PaymentStatus::PartiallyRefunded => [$payment->startProcessing(), $payment->succeed('psp-1'), $payment->refund('ref-1', Money::of(1_000, Currency::KZT()))],
        PaymentStatus::Refunded => [$payment->startProcessing(), $payment->succeed('psp-1'), $payment->refund('ref-1', Money::of(10_000, Currency::KZT()))],
    };

    return $payment;
}

it('starts as created with nothing refunded', function (): void {
    $payment = newPayment();

    expect($payment->status())->toBe(PaymentStatus::Created)
        ->and($payment->refunded()->isZero())->toBeTrue()
        ->and($payment->pspPaymentId())->toBeNull();
});

it('allows exactly the transitions of the state machine', function (PaymentStatus $from, PaymentStatus $to, bool $allowed): void {
    expect($from->canTransitionTo($to))->toBe($allowed);
})->with(function (): iterable {
    $allowed = [
        'created' => ['processing'],
        'processing' => ['succeeded', 'declined', 'failed'],
        'succeeded' => ['partially_refunded', 'refunded'],
        'partially_refunded' => ['partially_refunded', 'refunded'],
        'declined' => [],
        'failed' => [],
        'refunded' => [],
    ];

    foreach (PaymentStatus::cases() as $from) {
        foreach (PaymentStatus::cases() as $to) {
            yield "{$from->value} -> {$to->value}" => [$from, $to, in_array($to->value, $allowed[$from->value], true)];
        }
    }
});

it('marks only refunded, declined and failed as final', function (): void {
    expect(array_map(fn (PaymentStatus $s): string => $s->value, array_values(array_filter(PaymentStatus::cases(), fn (PaymentStatus $s): bool => $s->isFinal()))))
        ->toBe(['declined', 'failed', 'refunded']);
});

it('records the PSP reference on success', function (): void {
    $payment = paymentIn(PaymentStatus::Succeeded);

    expect($payment->status())->toBe(PaymentStatus::Succeeded)
        ->and($payment->pspPaymentId())->toBe('psp-1');
});

it('records the reason of a decline or failure', function (PaymentStatus $status, string $reason): void {
    expect(paymentIn($status)->failureReason())->toBe($reason);
})->with([
    [PaymentStatus::Declined, 'insufficient_funds'],
    [PaymentStatus::Failed, 'card_blocked'],
]);

it('refuses invalid transitions with a domain exception', function (PaymentStatus $from, Closure $action): void {
    $action(paymentIn($from));
})->throws(InvalidPaymentTransition::class)->with([
    'succeed before processing' => [PaymentStatus::Created, fn (Payment $p) => $p->succeed('psp-1')],
    'process twice' => [PaymentStatus::Processing, fn (Payment $p) => $p->startProcessing()],
    'decline a success' => [PaymentStatus::Succeeded, fn (Payment $p) => $p->decline('late')],
    'succeed a decline' => [PaymentStatus::Declined, fn (Payment $p) => $p->succeed('psp-1')],
    'fail a refund' => [PaymentStatus::Refunded, fn (Payment $p) => $p->fail('x')],
    'refund a processing payment' => [PaymentStatus::Processing, fn (Payment $p) => $p->refund('ref-9', Money::of(1, Currency::KZT()))],
    'refund a failed payment' => [PaymentStatus::Failed, fn (Payment $p) => $p->refund('ref-9', Money::of(1, Currency::KZT()))],
]);

it('refunds partially and then fully', function (): void {
    $payment = paymentIn(PaymentStatus::Succeeded);

    $first = $payment->refund('ref-1', Money::of(3_000, Currency::KZT()));
    expect($payment->status())->toBe(PaymentStatus::PartiallyRefunded)
        ->and($payment->refunded()->amount)->toBe(3_000)
        ->and($payment->refundable()->amount)->toBe(7_000)
        ->and($first->amount->amount)->toBe(3_000)
        ->and($first->paymentId)->toBe('pay-1');

    $payment->refund('ref-2', Money::of(7_000, Currency::KZT()));
    expect($payment->status())->toBe(PaymentStatus::Refunded)
        ->and($payment->refundable()->isZero())->toBeTrue();
});

it('refuses refunds above what is left or of nothing', function (int $amount): void {
    paymentIn(PaymentStatus::PartiallyRefunded)->refund('ref-2', Money::of($amount, Currency::KZT()));
})->throws(RefundNotAllowed::class)->with([
    'more than left' => 9_001,
    'zero' => 0,
]);

it('refuses a payment of nothing', function (): void {
    newPayment(0);
})->throws(InvalidArgumentException::class);

it('restores a payment from storage without replaying its history', function (): void {
    $payment = Payment::restore('pay-1', 'order-1', 'merchant-1', Money::of(10_000, Currency::KZT()), PaymentStatus::PartiallyRefunded, Money::of(4_000, Currency::KZT()), 'psp-1', null);

    expect($payment->status())->toBe(PaymentStatus::PartiallyRefunded)
        ->and($payment->refundable()->amount)->toBe(6_000);
});

it('keeps the PSP reference of a pending charge while processing', function (): void {
    $payment = paymentIn(PaymentStatus::Processing);
    $payment->recordPspReference('psp-pending');

    expect($payment->pspPaymentId())->toBe('psp-pending')
        ->and($payment->status())->toBe(PaymentStatus::Processing);

    paymentIn(PaymentStatus::Succeeded)->recordPspReference('psp-other');
})->throws(InvalidPaymentTransition::class);
