<?php

use App\Modules\Identity\Http\Controllers\AuthController;
use App\Modules\Identity\Http\Controllers\TokenController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::middleware('throttle:login')->group(function () {
        Route::post('login', [AuthController::class, 'login']);
        Route::post('tokens', [TokenController::class, 'store']);
    });

    Route::middleware(['auth:sanctum', 'account.usable'])->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::get('tokens', [TokenController::class, 'index']);
        Route::delete('tokens', [TokenController::class, 'destroyAll']);
        Route::delete('tokens/{token}', [TokenController::class, 'destroy'])->whereNumber('token');
    });
});
