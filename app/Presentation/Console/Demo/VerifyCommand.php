<?php

declare(strict_types=1);

namespace App\Presentation\Console\Demo;

use App\Infrastructure\Demo\GuaranteesVerifier;
use App\Infrastructure\Demo\VerifyCheck;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class VerifyCommand extends Command
{
    protected $signature = 'demo:verify
        {--wait=0 : Seconds to wait for the system to settle (no processing payments, outbox drained)}
        {--report= : Also write the result as JSON to this path}';

    protected $description = 'Check: no double charges, no lost events, a balanced ledger';

    public function handle(GuaranteesVerifier $verifier): int
    {
        $this->settle(is_numeric($this->option('wait')) ? (int) $this->option('wait') : 0);

        $checks = $verifier->checks();
        $summary = $verifier->summary();

        $this->table(['check', 'result', 'value'], array_map(
            fn (VerifyCheck $c): array => [$c->name, $c->passed ? 'ok' : 'FAILED', (string) $c->value],
            $checks,
        ));
        $this->table(['summary', 'count'], array_map(fn (string $k, int $v): array => [$k, (string) $v], array_keys($summary), $summary));

        $passed = array_reduce($checks, fn (bool $all, VerifyCheck $c): bool => $all && $c->passed, true);

        $report = $this->option('report');
        if (is_string($report) && $report !== '') {
            file_put_contents($report, json_encode([
                'passed' => $passed,
                'checks' => array_map(fn (VerifyCheck $c): array => ['check' => $c->name, 'passed' => $c->passed, 'value' => $c->value], $checks),
                'summary' => $summary,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }

        $passed ? $this->info('All guarantees hold.') : $this->error('Guarantees violated.');

        return $passed ? self::SUCCESS : self::FAILURE;
    }

    private function settle(int $seconds): void
    {
        $deadline = time() + $seconds;

        while (time() < $deadline) {
            $processing = DB::table('payments')->where('status', 'processing')->count()
                + DB::table('refunds')->where('status', 'processing')->count();
            $undelivered = DB::table('outbox_messages')->where('status', '!=', 'published')->count();
            $unbooked = DB::table('payments')->whereIn('status', ['succeeded', 'partially_refunded', 'refunded'])->count()
                - (int) DB::table('ledger_entries')->where('event_type', 'payment.succeeded')->distinct()->count('transaction_id');

            if ($processing === 0 && $undelivered === 0 && $unbooked <= 0) {
                return;
            }

            $this->line("settling: {$processing} payment(s) or refund(s) processing, {$undelivered} event(s) undelivered, {$unbooked} capture(s) not booked yet");
            sleep(5);
        }
    }
}
