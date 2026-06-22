<?php

use Illuminate\Support\Facades\Route;
use Modules\Payments\Http\Controllers\PaymentsController;
use Modules\Users\Http\Middleware\EnsureUserIsActive;

Route::middleware(['auth:sanctum', EnsureUserIsActive::class])->prefix('v1')->group(function () {
    Route::apiResource('payments', PaymentsController::class)->names('payments');
});
