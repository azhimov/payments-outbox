<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Ports\RefundRepository;
use App\Domain\Shared\Money;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

final class DatabaseRefundRepository implements RefundRepository
{
    public function status(string $refundId): ?string
    {
        $status = $this->table()->where('id', $refundId)->value('status');

        return match ($status) {
            'processing', 'succeeded', 'failed' => $status,
            default => null,
        };
    }

    public function amount(string $refundId): ?int
    {
        $amount = $this->table()->where('id', $refundId)->value('amount');

        return is_numeric($amount) ? (int) $amount : null;
    }

    public function reserved(string $paymentId): int
    {
        $sum = $this->table()->where('payment_id', $paymentId)->where('status', 'processing')->sum('amount');

        return (int) $sum;
    }

    public function createProcessing(string $refundId, string $paymentId, string $merchantId, Money $amount): void
    {
        $this->table()->insert([
            'id' => $refundId,
            'payment_id' => $paymentId,
            'merchant_id' => $merchantId,
            'amount' => $amount->amount,
            'currency' => $amount->currency->code,
            'status' => 'processing',
            'created_at' => Date::now(),
            'updated_at' => Date::now(),
        ]);
    }

    public function markSucceeded(string $refundId): void
    {
        $this->table()->where('id', $refundId)->update(['status' => 'succeeded', 'updated_at' => Date::now()]);
    }

    public function markFailed(string $refundId): void
    {
        $this->table()->where('id', $refundId)->update(['status' => 'failed', 'updated_at' => Date::now()]);
    }

    private function table(): Builder
    {
        return DB::table('refunds');
    }
}
