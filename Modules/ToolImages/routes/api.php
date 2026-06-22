<?php

use Illuminate\Support\Facades\Route;
use Modules\ToolImages\Http\Controllers\ToolImagesController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('tool-images', ToolImagesController::class)
        ->parameters(['tool-images' => 'toolImage'])
        ->names('toolImages');
});
