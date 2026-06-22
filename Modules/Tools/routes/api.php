<?php

use Illuminate\Support\Facades\Route;
use Modules\Tools\Http\Controllers\ToolsController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('tools', ToolsController::class)->names('tools');
});
