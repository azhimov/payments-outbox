<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $order_id
 * @property int $position
 * @property string $sku
 * @property string $name
 * @property int $quantity
 * @property int $unit_price
 */
final class OrderItemModel extends Model
{
    public $timestamps = false;

    protected $table = 'order_items';

    protected $fillable = ['order_id', 'position', 'sku', 'name', 'quantity', 'unit_price'];

    protected $casts = ['position' => 'integer', 'quantity' => 'integer', 'unit_price' => 'integer'];
}
