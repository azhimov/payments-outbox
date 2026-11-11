<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Ports\OrderRepository;
use App\Domain\Order\Order;
use App\Domain\Order\OrderItem;
use App\Domain\Order\OrderStatus;
use App\Domain\Shared\Currency;
use App\Domain\Shared\Money;
use App\Infrastructure\Persistence\Models\OrderItemModel;
use App\Infrastructure\Persistence\Models\OrderModel;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

final class EloquentOrderRepository implements OrderRepository
{
    public function save(Order $order): void
    {
        DB::transaction(function () use ($order): void {
            $model = OrderModel::query()->find($order->id);

            if ($model !== null) {
                // Items never change after an order is placed; only the status moves.
                $model->update(['status' => $order->status()->value]);

                return;
            }

            OrderModel::query()->create([
                'id' => $order->id,
                'merchant_id' => $order->merchantId,
                'status' => $order->status()->value,
                'currency' => $order->total()->currency->code,
                'total_amount' => $order->total()->amount,
            ]);

            foreach ($order->items() as $position => $item) {
                OrderItemModel::query()->create([
                    'order_id' => $order->id,
                    'position' => $position,
                    'sku' => $item->sku,
                    'name' => $item->name,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unitPrice->amount,
                ]);
            }
        });
    }

    public function find(string $id, string $merchantId): ?Order
    {
        $model = OrderModel::query()->with('items')->where('merchant_id', $merchantId)->find($id);

        if ($model === null) {
            return null;
        }

        $currency = Currency::from($model->currency);
        $items = array_values($model->items->map(
            fn (OrderItemModel $item): OrderItem => new OrderItem($item->sku, $item->name, $item->quantity, Money::of($item->unit_price, $currency)),
        )->all());

        if ($items === []) {
            throw new UnexpectedValueException("Order {$id} has no items.");
        }

        return Order::restore($model->id, $model->merchant_id, $items, OrderStatus::from($model->status));
    }
}
