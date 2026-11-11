<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $merchant_id
 * @property string $order_id
 * @property string $status
 * @property int $amount
 * @property string $currency
 * @property int $refunded_amount
 * @property string|null $psp_payment_id
 * @property string|null $failure_reason
 */
final class PaymentModel extends Model
{
    use HasUuids;

    protected $table = 'payments';

    protected $guarded = [];

    protected $casts = ['amount' => 'integer', 'refunded_amount' => 'integer'];
}
