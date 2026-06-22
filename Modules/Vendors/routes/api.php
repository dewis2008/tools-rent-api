<?php

use Illuminate\Support\Facades\Route;
use Modules\Users\Http\Middleware\EnsureUserIsActive;
use Modules\Vendors\Http\Controllers\VendorsController;

Route::middleware(['auth:sanctum', EnsureUserIsActive::class])->prefix('v1')->group(function () {
    Route::apiResource('vendors', VendorsController::class)->names('vendors');
});
