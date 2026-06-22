<?php

use Illuminate\Support\Facades\Route;
use Modules\LockCodes\Http\Controllers\LockCodesController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('lock-codes', LockCodesController::class)
        ->parameters(['lock-codes' => 'lockCode'])
        ->names('lockCodes');
});
