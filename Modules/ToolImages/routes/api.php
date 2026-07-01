<?php

use Illuminate\Support\Facades\Route;
use Modules\ToolImages\Http\Controllers\ToolImageFilesController;
use Modules\ToolImages\Http\Controllers\ToolImagesController;
use Modules\Users\Http\Middleware\EnsureUserIsActive;

Route::middleware(['auth:sanctum', EnsureUserIsActive::class])->prefix('v1')->group(function () {
    Route::get('tool-images/{toolImage}/file', [ToolImageFilesController::class, 'show'])
        ->name('toolImages.file');

    Route::apiResource('tool-images', ToolImagesController::class)
        ->middlewareFor(['store', 'update'], 'throttle:tool-image-uploads')
        ->parameters(['tool-images' => 'toolImage'])
        ->names('toolImages');
});
