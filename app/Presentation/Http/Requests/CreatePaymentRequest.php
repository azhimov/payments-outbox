<?php

declare(strict_types=1);

namespace App\Presentation\Http\Requests;

use App\Application\Payments\CreatePaymentCommand;
use App\Presentation\Http\Middleware\AuthenticateMerchant;
use Illuminate\Foundation\Http\FormRequest;

final class CreatePaymentRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            // A token from the PSP's card form, never a card number.
            'card_token' => ['required', 'string', 'max:64'],
        ];
    }

    public function toCommand(string $orderId): CreatePaymentCommand
    {
        return new CreatePaymentCommand(
            merchantId: AuthenticateMerchant::merchantId($this),
            orderId: $orderId,
            cardToken: $this->string('card_token')->toString(),
        );
    }
}
