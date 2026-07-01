<?php

use Illuminate\Support\Facades\Route;
use Modules\Users\Http\Controllers\AuthController;
use Modules\Users\Http\Controllers\EmailVerificationNotificationController;
use Modules\Users\Http\Controllers\UsersController;
use Modules\Users\Http\Controllers\VerifyEmailController;
use Modules\Users\Http\Middleware\EnsureUserCanAccessAuthSession;
use Modules\Users\Http\Middleware\EnsureUserIsActive;
use Modules\Users\Http\Middleware\EnsureUserIsAdmin;

Route::prefix('v1')->group(function () {
    Route::post('auth/register', [AuthController::class, 'register'])
        ->middleware('throttle:3,1')
        ->name('auth.register');
    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1')
        ->name('auth.login');
    Route::get('auth/email/verify/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');
    Route::post('auth/email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:3,1')
        ->name('verification.send');
    Route::get('auth/me', [AuthController::class, 'me'])
        ->middleware(['auth:sanctum', EnsureUserCanAccessAuthSession::class])
        ->name('auth.me');
    Route::post('auth/logout', [AuthController::class, 'logout'])
        ->middleware('auth:sanctum')
        ->name('auth.logout');

    Route::middleware(['auth:sanctum', EnsureUserIsActive::class])->group(function () {
        Route::apiResource('users', UsersController::class)
            ->middleware(EnsureUserIsAdmin::class)
            ->names('users');
    });
});
