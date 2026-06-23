<?php

use Illuminate\Support\Facades\Route;
use Modules\LockCodes\Http\Controllers\LockCodesController;
use Modules\Users\Http\Middleware\EnsureUserIsActive;

Route::middleware(['auth:sanctum', EnsureUserIsActive::class])->prefix('v1')->group(function () {
    Route::get('lock-codes/{lockCode}/reveal', [LockCodesController::class, 'reveal'])
        ->name('lockCodes.reveal');

    Route::apiResource('lock-codes', LockCodesController::class)
        ->parameters(['lock-codes' => 'lockCode'])
        ->names('lockCodes');
});
