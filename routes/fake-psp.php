<?php

declare(strict_types=1);

use App\FakePsp\FakePspController;
use Illuminate\Support\Facades\Route;

// Served by the fake-psp container only (FAKE_PSP_ENABLED=true).
Route::prefix('psp')->group(function (): void {
    Route::post('/payments', [FakePspController::class, 'charge']);
    Route::get('/payments/{paymentId}', [FakePspController::class, 'show']);
    Route::post('/payments/{paymentId}/refunds', [FakePspController::class, 'refund']);
    Route::get('/refunds/{refundId}', [FakePspController::class, 'refundStatus']);
});
