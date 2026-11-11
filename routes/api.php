<?php

declare(strict_types=1);

use App\Presentation\Http\Controllers\OrderController;
use App\Presentation\Http\Controllers\PaymentController;
use App\Presentation\Http\Controllers\PspWebhookController;
use App\Presentation\Http\Middleware\AuthenticateMerchant;
use App\Presentation\Http\Middleware\VerifyPspSignature;
use Illuminate\Support\Facades\Route;

// Auth first, so idempotency keys are scoped to the merchant.
Route::middleware(AuthenticateMerchant::class)->group(function (): void {
    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/orders/{order}', [OrderController::class, 'show'])->whereUuid('order');

    Route::post('/orders/{order}/payments', [PaymentController::class, 'store'])
        ->whereUuid('order')
        ->middleware('idempotent:required');
    Route::get('/payments/{payment}', [PaymentController::class, 'show'])->whereUuid('payment');
});

// Called by the PSP, authenticated by its signature instead of a merchant key.
Route::post('/webhooks/psp', PspWebhookController::class)->middleware(VerifyPspSignature::class);
