<?php

declare(strict_types=1);

namespace App\FakePsp;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $payment_id
 * @property int $amount
 * @property string $currency
 * @property string $status
 * @property string|null $decline_reason
 * @property string|null $final_status
 * @property string|null $webhook_url
 * @property Carbon|null $webhook_sent_at
 */
final class FakePspCharge extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'fake_psp_charges';

    protected $guarded = [];

    protected $casts = ['amount' => 'integer', 'webhook_sent_at' => 'datetime'];

    /**
     * @return array<string, mixed>
     */
    public function toApi(): array
    {
        return array_filter([
            'id' => $this->id,
            'payment_id' => $this->payment_id,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'decline_reason' => $this->decline_reason,
        ], fn (mixed $value): bool => $value !== null);
    }
}
