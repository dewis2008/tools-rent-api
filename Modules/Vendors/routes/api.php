<?php

use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Modules\Users\Http\Middleware\EnsureUserIsActive;
use Modules\Vendors\Http\Controllers\VendorsController;

Route::prefix('v1')->group(function () {
    Route::middleware(['auth:sanctum', CheckAbilities::class.':vendor:onboarding'])->group(function () {
        Route::post('vendors', [VendorsController::class, 'store'])->name('vendors.store');
        Route::get('vendors/{vendor}', [VendorsController::class, 'show'])->name('vendors.show');
        Route::match(['put', 'patch'], 'vendors/{vendor}', [VendorsController::class, 'update'])->name('vendors.update');
    });

    Route::middleware(['auth:sanctum', EnsureUserIsActive::class])->group(function () {
        Route::get('vendors', [VendorsController::class, 'index'])->name('vendors.index');
        Route::delete('vendors/{vendor}', [VendorsController::class, 'destroy'])->name('vendors.destroy');
    });
});
