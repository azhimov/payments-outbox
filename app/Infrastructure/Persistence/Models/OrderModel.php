<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $merchant_id
 * @property string $status
 * @property string $currency
 * @property int $total_amount
 * @property-read Collection<int, OrderItemModel> $items
 */
final class OrderModel extends Model
{
    use HasUuids;

    protected $table = 'orders';

    protected $fillable = ['id', 'merchant_id', 'status', 'currency', 'total_amount'];

    protected $casts = ['total_amount' => 'integer'];

    /**
     * @return HasMany<OrderItemModel, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItemModel::class, 'order_id')->orderBy('position');
    }
}
