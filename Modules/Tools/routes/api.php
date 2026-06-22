<?php

use Illuminate\Support\Facades\Route;
use Modules\Tools\Http\Controllers\ToolsController;
use Modules\Users\Http\Middleware\EnsureUserIsActive;

Route::middleware(['auth:sanctum', EnsureUserIsActive::class])->prefix('v1')->group(function () {
    Route::apiResource('tools', ToolsController::class)->names('tools');
});
