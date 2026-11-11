<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Payments\Exceptions\PaymentAlreadyInProgress;
use App\Application\Ports\PaymentRepository;
use App\Domain\Payment\Payment;
use App\Domain\Payment\PaymentStatus;
use App\Domain\Shared\Currency;
use App\Domain\Shared\Money;
use App\Infrastructure\Persistence\Models\PaymentModel;
use Illuminate\Database\UniqueConstraintViolationException;

final class EloquentPaymentRepository implements PaymentRepository
{
    public function save(Payment $payment): void
    {
        try {
            PaymentModel::query()->updateOrCreate(['id' => $payment->id], [
                'merchant_id' => $payment->merchantId,
                'order_id' => $payment->orderId,
                'status' => $payment->status()->value,
                'amount' => $payment->amount->amount,
                'currency' => $payment->amount->currency->code,
                'refunded_amount' => $payment->refunded()->amount,
                'psp_payment_id' => $payment->pspPaymentId(),
                'failure_reason' => $payment->failureReason(),
            ]);
        } catch (UniqueConstraintViolationException $e) {
            // payments_one_live_per_order: another payment of this order is processing or succeeded.
            throw new PaymentAlreadyInProgress('The order already has a live payment.', 0, $e);
        }
    }

    public function find(string $id, string $merchantId): ?Payment
    {
        $model = PaymentModel::query()->where('merchant_id', $merchantId)->find($id);

        return $model === null ? null : self::toDomain($model);
    }

    public function findForUpdate(string $id): ?Payment
    {
        $model = PaymentModel::query()->lockForUpdate()->find($id);

        return $model === null ? null : self::toDomain($model);
    }

    public function processingSince(\DateTimeImmutable $before, int $limit): array
    {
        return array_values(PaymentModel::query()
            ->where('status', PaymentStatus::Processing->value)
            ->where('updated_at', '<', $before)
            ->orderBy('updated_at')
            ->limit($limit)
            ->pluck('id')
            ->filter(fn (mixed $id): bool => is_string($id))
            ->all());
    }

    private static function toDomain(PaymentModel $model): Payment
    {
        $currency = Currency::from($model->currency);

        return Payment::restore(
            $model->id,
            $model->order_id,
            $model->merchant_id,
            Money::of($model->amount, $currency),
            PaymentStatus::from($model->status),
            Money::of($model->refunded_amount, $currency),
            $model->psp_payment_id,
            $model->failure_reason,
        );
    }
}
