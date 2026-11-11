<?php

declare(strict_types=1);

use App\Application\Ports\OrderRepository;
use App\Domain\Order\Order;
use App\Domain\Order\OrderItem;
use App\Domain\Order\OrderStatus;
use App\Domain\Shared\Currency;
use App\Domain\Shared\Money;
use Illuminate\Support\Str;

it('stores and restores an order with its items and status', function (): void {
    [$merchantId] = merchantWithKey();
    $repository = app(OrderRepository::class);
    $id = (string) Str::uuid7();

    $order = Order::place($id, $merchantId, [
        new OrderItem('SKU-TEA', 'Green tea', 2, Money::of(150_000, Currency::KZT())),
        new OrderItem('SKU-CUP', 'Cup', 1, Money::of(200_000, Currency::KZT())),
    ]);
    $repository->save($order);

    $order->markPaid();
    $repository->save($order);

    $restored = $repository->find($id, $merchantId);

    expect($restored)->not->toBeNull()
        ->and($restored->status())->toBe(OrderStatus::Paid)
        ->and($restored->total()->amount)->toBe(500_000)
        ->and(array_map(fn (OrderItem $i): string => $i->sku, $restored->items()))->toBe(['SKU-TEA', 'SKU-CUP'])
        ->and($repository->find($id, (string) Str::uuid7()))->toBeNull();
});
