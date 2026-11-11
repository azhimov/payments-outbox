<?php

declare(strict_types=1);

use App\Application\Ports\ChargeOutcome;
use App\Application\Ports\ChargeRequest;
use App\Application\Ports\ChargeResult;
use App\Application\Ports\RefundRequest;
use App\Domain\Shared\Currency;
use App\Domain\Shared\Money;
use App\Infrastructure\Psp\HttpPaymentGateway;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/*
| The real HttpPaymentGateway against the real fake-psp, over HTTP, in a separate PHP
| server process. Needs PostgreSQL: the server and the test share the database.
*/

beforeAll(function (): void {
    if (getenv('DB_CONNECTION') !== 'pgsql') {
        return;
    }

    $port = 18_000 + random_int(0, 999);
    $server = new Process(['php', '-S', "127.0.0.1:{$port}", '-t', 'public'], dirname(__DIR__, 2), [
        ...getenv(),
        'APP_ENV' => 'testing',
        'FAKE_PSP_ENABLED' => 'true',
        'FAKE_PSP_LATENCY_MS' => '0',
        'FAKE_PSP_TIMEOUT_SLEEP_MS' => '2500',
        // A held-back "timeout" answer must not block the next request.
        'PHP_CLI_SERVER_WORKERS' => '4',
    ]);
    $server->start();

    for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
        usleep(100_000);
    }

    $GLOBALS['fakePspServer'] = $server;
    $GLOBALS['fakePspUrl'] = "http://127.0.0.1:{$port}";
});

afterAll(function (): void {
    $server = $GLOBALS['fakePspServer'] ?? null;

    if ($server instanceof Process && $server->getPid() !== null) {
        // php -S with workers forks children that outlive a stopped parent.
        exec('pkill -P '.$server->getPid());
        $server->stop(1);
    }
});

beforeEach(function (): void {
    if (getenv('DB_CONNECTION') !== 'pgsql') {
        $this->markTestSkipped('contract tests run against PostgreSQL (make test-pgsql, CI)');
    }

    // The migrated schema must be visible to the server process, so no RefreshDatabase here.
    $this->artisan('migrate');
    $this->gateway = new HttpPaymentGateway(app(Factory::class), $GLOBALS['fakePspUrl'], timeout: 1.0, connectTimeout: 1.0);
});

function chargeWith(string $token, ?string $paymentId = null): ChargeResult
{
    return test()->gateway->charge(new ChargeRequest($paymentId ?? (string) Str::uuid7(), Money::of(500_000, Currency::KZT()), $token));
}

it('maps each PSP answer to a charge outcome', function (string $token, ChargeOutcome $outcome): void {
    $result = chargeWith($token);

    expect($result->outcome)->toBe($outcome);
    if (in_array($outcome, [ChargeOutcome::Succeeded, ChargeOutcome::Pending, ChargeOutcome::Declined], true)) {
        expect($result->pspPaymentId)->toStartWith('psp_');
    }
})->with([
    'success' => ['tok_success', ChargeOutcome::Succeeded],
    'decline' => ['tok_decline', ChargeOutcome::Declined],
    'pending' => ['tok_pending', ChargeOutcome::Pending],
    'error' => ['tok_error', ChargeOutcome::Unknown],
    'timeout' => ['tok_timeout', ChargeOutcome::Unknown],
]);

it('passes the decline reason through', function (): void {
    expect(chargeWith('tok_decline')->reason)->toBe('insufficient_funds');
});

it('learns from a status query that a timed-out charge went through', function (): void {
    $paymentId = (string) Str::uuid7();

    expect(chargeWith('tok_timeout', $paymentId)->outcome)->toBe(ChargeOutcome::Unknown);

    usleep(1_600_000); // the server finishes its late answer
    $status = $this->gateway->status($paymentId);

    expect($status->outcome)->toBe(ChargeOutcome::Succeeded)
        ->and($status->pspPaymentId)->toStartWith('psp_');
});

it('learns from a status query that a 503 charged nothing', function (): void {
    $paymentId = (string) Str::uuid7();
    chargeWith('tok_error', $paymentId);

    expect($this->gateway->status($paymentId)->outcome)->toBe(ChargeOutcome::NotFound);
});

it('gets the same charge back when it retries with the same payment id', function (): void {
    $paymentId = (string) Str::uuid7();

    $first = chargeWith('tok_success', $paymentId);
    $retry = chargeWith('tok_success', $paymentId);

    expect($retry->outcome)->toBe(ChargeOutcome::Succeeded)
        ->and($retry->pspPaymentId)->toBe($first->pspPaymentId);
});

it('reports an unreachable PSP as unknown', function (): void {
    $gateway = new HttpPaymentGateway(app(Factory::class), 'http://127.0.0.1:1', timeout: 0.5, connectTimeout: 0.5);

    expect($gateway->charge(new ChargeRequest((string) Str::uuid7(), Money::of(1, Currency::KZT()), 'tok_success'))->outcome)
        ->toBe(ChargeOutcome::Unknown);
});

it('refunds through the PSP once per refund id and maps a refusal to declined', function (): void {
    $paymentId = (string) Str::uuid7();
    chargeWith('tok_success', $paymentId);
    $request = fn (string $refundId, int $amount) => new RefundRequest($refundId, $paymentId, Money::of($amount, Currency::KZT()));

    $first = $this->gateway->refund($request('rf-'.$paymentId, 100_000));
    $retry = $this->gateway->refund($request('rf-'.$paymentId, 100_000));
    $tooMuch = $this->gateway->refund($request('rf2-'.$paymentId, 500_000));

    expect($first->outcome)->toBe(ChargeOutcome::Succeeded)
        ->and($retry->outcome)->toBe(ChargeOutcome::Succeeded)
        ->and($tooMuch->outcome)->toBe(ChargeOutcome::Declined)
        ->and($tooMuch->reason)->toBe('refund_exceeds_capture');
});

it('asks the PSP whether a refund was made', function (): void {
    $paymentId = (string) Str::uuid7();
    chargeWith('tok_success', $paymentId);
    $this->gateway->refund(new RefundRequest('rfs-'.$paymentId, $paymentId, Money::of(1_000, Currency::KZT())));

    expect($this->gateway->refundStatus('rfs-'.$paymentId)->outcome)->toBe(ChargeOutcome::Succeeded)
        ->and($this->gateway->refundStatus('rfs-none-'.$paymentId)->outcome)->toBe(ChargeOutcome::NotFound);
});
