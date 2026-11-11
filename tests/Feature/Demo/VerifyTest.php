<?php

declare(strict_types=1);

use App\Application\Ledger\IncomingEvent;
use App\Application\Ledger\RecordLedgerEntries;
use App\Application\Ports\PaymentGateway;
use App\FakePsp\FakePspCharge;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Doubles\ScriptedGateway;

beforeEach(function (): void {
    [, $this->key] = merchantWithKey();
    app()->instance(PaymentGateway::class, new ScriptedGateway);
});

/**
 * A consistent world: a paid order, its PSP charge, the delivered event, the ledger entries.
 */
function settledPayment(): string
{
    $paymentId = pay(test()->key, createOrder(test()->key), 'k-'.uniqid())->json('data.id');
    FakePspCharge::query()->create(['id' => 'psp_'.uniqid(), 'payment_id' => $paymentId, 'amount' => 500_000, 'currency' => 'KZT', 'status' => 'succeeded']);
    DB::table('outbox_messages')->update(['status' => 'published']);
    app(RecordLedgerEntries::class)->handle(new IncomingEvent((string) Str::uuid7(), 'payment.succeeded', ['payment_id' => $paymentId, 'amount' => 500_000, 'currency' => 'KZT']));

    return $paymentId;
}

it('passes when every guarantee holds', function (): void {
    settledPayment();
    settledPayment();

    $path = tempnam(sys_get_temp_dir(), 'chaos-report-');
    $this->artisan('demo:verify', ['--report' => $path])
        ->expectsOutputToContain('All guarantees hold.')
        ->assertSuccessful();

    $report = json_decode((string) file_get_contents($path), true);
    unlink($path);
    expect($report['passed'])->toBeTrue()
        ->and($report['summary']['orders_paid'])->toBe(2);
});

it('catches a double charge at the PSP', function (): void {
    $paymentId = settledPayment();
    // A second successful charge for the same order, under another payment.
    $orderId = DB::table('payments')->where('id', $paymentId)->value('order_id');
    DB::table('payments')->where('id', $paymentId)->update(['status' => 'failed']);
    $other = pay($this->key, createOrder($this->key), 'k-x')->json('data.id');
    DB::table('payments')->where('id', $other)->update(['order_id' => $orderId]);
    FakePspCharge::query()->create(['id' => 'psp_dup', 'payment_id' => $other, 'amount' => 500_000, 'currency' => 'KZT', 'status' => 'succeeded']);

    $this->artisan('demo:verify')->expectsOutputToContain('Guarantees violated.')->assertFailed();
});

it('catches money taken that the service does not know about', function (): void {
    $paymentId = settledPayment();
    DB::table('payments')->where('id', $paymentId)->update(['status' => 'failed']);

    $this->artisan('demo:verify')->assertFailed();
});

it('catches a lost event: a capture never booked in the ledger', function (): void {
    settledPayment();
    DB::table('ledger_entries')->delete();

    $this->artisan('demo:verify')->assertFailed();
});

it('catches an event booked twice', function (): void {
    $paymentId = settledPayment();
    app(RecordLedgerEntries::class)->handle(new IncomingEvent((string) Str::uuid7(), 'payment.succeeded', ['payment_id' => $paymentId, 'amount' => 500_000, 'currency' => 'KZT']));

    $this->artisan('demo:verify')->assertFailed();
});

it('catches undelivered events and stuck payments', function (string $table, array $change): void {
    settledPayment();
    DB::table($table)->update($change);

    $this->artisan('demo:verify')->assertFailed();
})->with([
    'outbox not drained' => ['outbox_messages', ['status' => 'pending']],
    'payment stuck' => ['payments', ['status' => 'processing']],
]);

it('waits for the system to settle before checking', function (): void {
    settledPayment();
    DB::table('outbox_messages')->update(['status' => 'pending']);

    $this->artisan('demo:verify', ['--wait' => 1])
        ->expectsOutputToContain('settling: 0 payment(s) or refund(s) processing, 1 event(s) undelivered')
        ->assertFailed();
});
