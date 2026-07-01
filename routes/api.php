<?php

use App\Http\Controllers\AdminSummariesController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\Users\Http\Middleware\EnsureUserIsActive;
use Modules\Users\Http\Middleware\EnsureUserIsAdmin;
use Modules\Users\Http\Resources\UsersResource;

Route::middleware(['auth:sanctum', EnsureUserIsActive::class, EnsureUserIsAdmin::class])
    ->prefix('v1')
    ->group(function (): void {
        Route::get('admin/summary', [AdminSummariesController::class, 'show'])
            ->name('admin.summary');
    });

Route::get('/user', function (Request $request) {
    return new UsersResource($request->user());
})->middleware(['auth:sanctum', EnsureUserIsActive::class]);
