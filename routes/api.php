<?php

declare(strict_types=1);

use App\Presentation\Http\Controllers\OrderController;
use App\Presentation\Http\Middleware\AuthenticateMerchant;
use Illuminate\Support\Facades\Route;

Route::middleware(AuthenticateMerchant::class)->group(function (): void {
    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/orders/{order}', [OrderController::class, 'show'])->whereUuid('order');
});
