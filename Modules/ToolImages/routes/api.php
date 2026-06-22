<?php

use Illuminate\Support\Facades\Route;
use Modules\ToolImages\Http\Controllers\ToolImagesController;
use Modules\Users\Http\Middleware\EnsureUserIsActive;

Route::middleware(['auth:sanctum', EnsureUserIsActive::class])->prefix('v1')->group(function () {
    Route::apiResource('tool-images', ToolImagesController::class)
        ->parameters(['tool-images' => 'toolImage'])
        ->names('toolImages');
});
