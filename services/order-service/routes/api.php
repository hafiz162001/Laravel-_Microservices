<?php

use App\Http\Controllers\OrderController;
use App\Http\Middleware\VerifyJwt;
use Illuminate\Support\Facades\Route;

// Public health check
Route::get('/health', [OrderController::class, 'health']);

// Protected routes (Stateless JWT Verification)
Route::middleware([VerifyJwt::class])->group(function () {
    Route::get('/', [OrderController::class, 'index']);
    Route::post('/', [OrderController::class, 'store']);
});

