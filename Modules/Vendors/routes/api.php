<?php

use Illuminate\Support\Facades\Route;
use Modules\Vendors\Http\Controllers\VendorsController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('vendors', VendorsController::class)->names('vendors');
});
