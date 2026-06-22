<?php

use Illuminate\Support\Facades\Route;
use Modules\Users\Http\Controllers\AuthController;
use Modules\Users\Http\Controllers\UsersController;

Route::prefix('v1')->group(function () {
    Route::post('auth/register', [AuthController::class, 'register'])->name('auth.register');
    Route::post('auth/login', [AuthController::class, 'login'])->name('auth.login');

    Route::middleware(['auth:sanctum'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::apiResource('users', UsersController::class)->names('users');
    });
});
