<?php

use Illuminate\Support\Facades\Route;
use Modules\ToolImages\Http\Controllers\ToolImagesController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('toolimages', ToolImagesController::class)->names('toolimages');
});
