<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\LookupController;
use App\Http\Controllers\ProductController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::apiResource('products', ProductController::class);
        Route::get('categories', [LookupController::class, 'categories']);
        Route::get('suppliers', [LookupController::class, 'suppliers']);
    });
});
