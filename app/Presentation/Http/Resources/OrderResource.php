<?php

declare(strict_types=1);

namespace App\Presentation\Http\Resources;

use App\Domain\Order\Order;
use App\Domain\Order\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Order $resource
 */
final class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $order = $this->resource;

        return [
            'id' => $order->id,
            'status' => $order->status()->value,
            'currency' => $order->total()->currency->code,
            'total_amount' => $order->total()->amount,
            'items' => array_map(fn (OrderItem $item): array => [
                'sku' => $item->sku,
                'name' => $item->name,
                'quantity' => $item->quantity,
                'unit_price' => $item->unitPrice->amount,
            ], $order->items()),
        ];
    }
}
