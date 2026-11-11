<?php

declare(strict_types=1);

use App\Application\Payments\PaymentOutcomes;
use App\Application\Ports\ChargeOutcome;
use App\Application\Ports\ChargeResult;
use App\Application\Ports\IntegrationEvent;
use App\Application\Ports\IntegrationEvents;
use App\Application\Ports\PaymentGateway;
use Illuminate\Support\Facades\DB;
use Tests\Doubles\ScriptedGateway;

beforeEach(function (): void {
    [, $this->key] = merchantWithKey();
    app()->instance(PaymentGateway::class, $this->gateway = new ScriptedGateway(new ChargeResult(ChargeOutcome::Unknown)));
    $this->orderId = createOrder($this->key);
    $this->paymentId = pay($this->key, $this->orderId, 'key-1')->assertAccepted()->json('data.id');
});

it('rolls back the payment, the order and the event together when any of them fails', function (): void {
    app()->instance(IntegrationEvents::class, new class implements IntegrationEvents
    {
        public function publish(IntegrationEvent $event): void
        {
            throw new RuntimeException('outbox is down');
        }
    });
    app()->forgetInstance(PaymentOutcomes::class);

    expect(fn () => app(PaymentOutcomes::class)->apply($this->paymentId, new ChargeResult(ChargeOutcome::Succeeded, 'psp_1')))
        ->toThrow(RuntimeException::class, 'outbox is down');

    expect(DB::table('payments')->where('id', $this->paymentId)->value('status'))->toBe('processing')
        ->and(DB::table('orders')->where('id', $this->orderId)->value('status'))->toBe('pending')
        ->and(DB::table('outbox_messages')->count())->toBe(0);
});

it('applies an outcome once: the second report of the same payment is a no-op', function (): void {
    $outcomes = app(PaymentOutcomes::class);

    $outcomes->apply($this->paymentId, new ChargeResult(ChargeOutcome::Succeeded, 'psp_1'));
    $again = $outcomes->apply($this->paymentId, new ChargeResult(ChargeOutcome::Succeeded, 'psp_1'));
    $late = $outcomes->apply($this->paymentId, new ChargeResult(ChargeOutcome::NotFound));

    expect($again->status()->value)->toBe('succeeded')
        ->and($late->status()->value)->toBe('succeeded')
        ->and(DB::table('outbox_messages')->count())->toBe(1);
});

it('fails a payment the PSP never charged', function (): void {
    $payment = app(PaymentOutcomes::class)->apply($this->paymentId, new ChargeResult(ChargeOutcome::NotFound));

    expect($payment->status()->value)->toBe('failed')
        ->and($payment->failureReason())->toBe('not_charged_at_psp');
});
