<?php

use Illuminate\Support\Facades\Route;
use Modules\LockCodes\Http\Controllers\LockCodesController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('lockcodes', LockCodesController::class)->names('lockcodes');
});
