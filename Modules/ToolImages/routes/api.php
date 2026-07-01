<?php

use Illuminate\Support\Facades\Route;
use Modules\ToolImages\Http\Controllers\ToolImageFilesController;
use Modules\ToolImages\Http\Controllers\ToolImagesController;
use Modules\Users\Http\Middleware\EnsureUserIsActive;
use Modules\Users\Http\Middleware\ResolveOptionalSanctumUser;

Route::prefix('v1')->group(function () {
    Route::get('tool-images/{toolImage}/file', [ToolImageFilesController::class, 'show'])
        ->middleware(ResolveOptionalSanctumUser::class)
        ->name('toolImages.file');

    Route::apiResource('tool-images', ToolImagesController::class)
        ->middleware(['auth:sanctum', EnsureUserIsActive::class])
        ->middlewareFor(['store', 'update'], 'throttle:tool-image-uploads')
        ->parameters(['tool-images' => 'toolImage'])
        ->names('toolImages');
});
