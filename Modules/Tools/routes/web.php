<?php

use Illuminate\Support\Facades\Route;
use Modules\Tools\Http\Controllers\ToolsController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('tools', ToolsController::class)->names('tools');
});
