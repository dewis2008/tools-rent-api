<?php

use Illuminate\Support\Facades\Route;
use Modules\Categories\Http\Controllers\CategoriesController;
use Modules\Users\Http\Middleware\EnsureUserIsActive;
use Modules\Users\Http\Middleware\ResolveOptionalSanctumUser;

Route::prefix('v1')->group(function () {
    Route::apiResource('categories', CategoriesController::class)
        ->only(['index', 'show'])
        ->middleware(ResolveOptionalSanctumUser::class)
        ->names('categories');

    Route::apiResource('categories', CategoriesController::class)
        ->except(['index', 'show'])
        ->middleware(['auth:sanctum', EnsureUserIsActive::class])
        ->names('categories');
});
