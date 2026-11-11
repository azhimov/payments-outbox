<?php

declare(strict_types=1);

use App\Presentation\Http\Controllers\MetricsController;
use Illuminate\Support\Facades\Route;

// Prometheus scrapes this from inside the compose network.
Route::get('/metrics', MetricsController::class);
