<?php

use Illuminate\Support\Facades\Route;
use Modules\Vendors\Http\Controllers\VendorsController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('vendors', VendorsController::class)->names('vendors');
});
