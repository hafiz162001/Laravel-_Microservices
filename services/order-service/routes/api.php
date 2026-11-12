<?php

use App\Http\Controllers\OrderController;
use App\Http\Middleware\VerifyJwt;
use Illuminate\Support\Facades\Route;

// Public health, stock check (gRPC proxy) & Prometheus metrics
Route::get('/health', [OrderController::class, 'health']);
Route::get('/metrics', [OrderController::class, 'metrics']);
Route::get('/stock', [OrderController::class, 'checkStock']);

// Protected routes (Stateless JWT + Redis Rate Limiting)
Route::middleware([VerifyJwt::class, 'throttle:api'])->group(function () {
    Route::get('/', [OrderController::class, 'index']);
    Route::post('/', [OrderController::class, 'store']);
});
