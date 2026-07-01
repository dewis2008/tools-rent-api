<?php

use Illuminate\Support\Facades\Route;
use Modules\Tools\Http\Controllers\ToolsController;
use Modules\Users\Http\Middleware\EnsureUserIsActive;
use Modules\Users\Http\Middleware\ResolveOptionalSanctumUser;

Route::prefix('v1')->group(function () {
    Route::apiResource('tools', ToolsController::class)
        ->only(['index', 'show'])
        ->middleware(ResolveOptionalSanctumUser::class)
        ->names('tools');

    Route::apiResource('tools', ToolsController::class)
        ->except(['index', 'show'])
        ->middleware(['auth:sanctum', EnsureUserIsActive::class])
        ->names('tools');
});
