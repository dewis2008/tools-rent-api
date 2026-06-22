<?php

use Illuminate\Support\Facades\Route;
use Modules\Categories\Http\Controllers\CategoriesController;
use Modules\Users\Http\Middleware\EnsureUserIsActive;

Route::middleware(['auth:sanctum', EnsureUserIsActive::class])->prefix('v1')->group(function () {
    Route::apiResource('categories', CategoriesController::class)->names('categories');
});
