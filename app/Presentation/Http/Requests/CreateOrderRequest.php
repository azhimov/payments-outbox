<?php

declare(strict_types=1);

namespace App\Presentation\Http\Requests;

use App\Application\Orders\CreateOrderCommand;
use App\Application\Orders\OrderItemData;
use App\Presentation\Http\Middleware\AuthenticateMerchant;
use Illuminate\Foundation\Http\FormRequest;

final class CreateOrderRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'currency' => ['required', 'string', 'in:KZT'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.sku' => ['required', 'string', 'max:64'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            // Minor units: an integer, never a float.
            'items.*.unit_price' => ['required', 'integer', 'min:1'],
        ];
    }

    public function toCommand(): CreateOrderCommand
    {
        /** @var array{currency: string, items: list<array{sku: string, name: string, quantity: int, unit_price: int}>} $data */
        $data = $this->validated();

        return new CreateOrderCommand(
            merchantId: AuthenticateMerchant::merchantId($this),
            currency: $data['currency'],
            items: array_map(
                fn (array $item): OrderItemData => new OrderItemData($item['sku'], $item['name'], $item['quantity'], $item['unit_price']),
                $data['items'],
            ),
        );
    }
}
