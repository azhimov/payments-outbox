<?php

declare(strict_types=1);

namespace App\FakePsp;

use App\Infrastructure\Psp\PspSignature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;

/**
 * The fake PSP's webhook sender: settles pending charges after a delay and tells the
 * merchant, sometimes twice with the same event id, like real providers retrying.
 */
final class DeliverWebhooksCommand extends Command
{
    protected $signature = 'psp:deliver-webhooks {--once : One pass, then exit}';

    protected $description = 'Fake PSP: send webhooks for settled pending charges';

    public function handle(Http $http, PspSignature $signature): int
    {
        do {
            $sent = $this->pass($http, $signature);

            if ($this->option('once') !== true && $sent === 0) {
                usleep(500_000);
            }
        } while ($this->option('once') !== true);

        return self::SUCCESS;
    }

    private function pass(Http $http, PspSignature $signature): int
    {
        $due = Date::now()->subMilliseconds(config()->integer('fake_psp.webhook_delay_ms'));
        $charges = FakePspCharge::query()
            ->where('status', 'pending')
            ->whereNotNull('webhook_url')
            ->where('created_at', '<=', $due)
            ->limit(50)
            ->get();

        foreach ($charges as $charge) {
            $charge->status = $charge->final_status ?? 'succeeded';
            $charge->webhook_event_id ??= 'evt_'.Str::lower(Str::random(24));
            $charge->save();

            $times = random_int(1, 1_000_000) / 1_000_000 <= config()->float('fake_psp.webhook_duplicate_rate') ? 2 : 1;
            for ($i = 0; $i < $times; $i++) {
                $this->send($http, $signature, $charge);
            }

            $charge->webhook_sent_at = Date::now();
            $charge->save();
        }

        return $charges->count();
    }

    private function send(Http $http, PspSignature $signature, FakePspCharge $charge): void
    {
        $body = json_encode([
            'id' => $charge->webhook_event_id,
            'type' => 'payment.'.$charge->status,
            'data' => array_filter([
                'payment_id' => $charge->payment_id,
                'psp_payment_id' => $charge->id,
                'status' => $charge->status,
                'decline_reason' => $charge->decline_reason,
            ], fn (mixed $v): bool => $v !== null),
        ], JSON_THROW_ON_ERROR);
        $timestamp = Date::now()->getTimestamp();

        try {
            $http->withBody($body, 'application/json')
                ->withHeaders([
                    PspSignature::TIMESTAMP_HEADER => (string) $timestamp,
                    PspSignature::SIGNATURE_HEADER => $signature->sign($timestamp, $body),
                ])
                ->timeout(5)
                ->post((string) $charge->webhook_url);
        } catch (ConnectionException) {
            // The merchant is down: reconcile on its side will catch up.
        }
    }
}
