<?php

declare(strict_types=1);

use App\Domain\Order\Exceptions\InvalidOrder;
use App\Domain\Order\Order;
use App\Domain\Order\OrderItem;
use App\Domain\Order\OrderStatus;
use App\Domain\Shared\Currency;
use App\Domain\Shared\Money;

function demoOrder(): Order
{
    return Order::place('order-1', 'merchant-1', [
        new OrderItem('SKU-TEA', 'Green tea', 2, Money::of(1_500_00, Currency::KZT())),
        new OrderItem('SKU-CUP', 'Cup', 1, Money::of(2_000_00, Currency::KZT())),
    ]);
}

it('totals its items', function (): void {
    $order = demoOrder();

    expect($order->total()->amount)->toBe(5_000_00)
        ->and($order->status())->toBe(OrderStatus::Pending)
        ->and($order->items())->toHaveCount(2);
});

it('is paid once', function (): void {
    $order = demoOrder();
    $order->markPaid();

    expect($order->status())->toBe(OrderStatus::Paid);

    $order->markPaid();
})->throws(InvalidOrder::class);

it('needs at least one item', function (): void {
    Order::place('order-1', 'merchant-1', []);
})->throws(InvalidOrder::class);

it('refuses items with a quantity below one', function (): void {
    new OrderItem('SKU-TEA', 'Green tea', 0, Money::of(100, Currency::KZT()));
})->throws(InvalidOrder::class);

it('refuses items without a SKU or name', function (string $sku, string $name): void {
    new OrderItem($sku, $name, 1, Money::of(100, Currency::KZT()));
})->throws(InvalidOrder::class)->with([['', 'Tea'], ['SKU', ' ']]);
