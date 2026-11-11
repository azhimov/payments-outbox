<?php

declare(strict_types=1);

namespace App\FakePsp;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $refund_id
 * @property string $payment_id
 * @property int $amount
 */
final class FakePspRefund extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'fake_psp_refunds';

    protected $guarded = [];

    protected $casts = ['amount' => 'integer'];

    /**
     * @return array<string, mixed>
     */
    public function toApi(): array
    {
        return ['id' => $this->id, 'refund_id' => $this->refund_id, 'payment_id' => $this->payment_id, 'amount' => $this->amount, 'status' => 'succeeded'];
    }
}
