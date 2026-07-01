<?php

use Illuminate\Support\Facades\Route;
use Modules\Payments\Http\Controllers\PaymentsController;
use Modules\Payments\Http\Controllers\StripePaymentIntentsController;
use Modules\Payments\Http\Controllers\StripeWebhooksController;
use Modules\Users\Http\Middleware\EnsureUserIsActive;

Route::prefix('v1')->group(function () {
    Route::post('stripe/webhooks', StripeWebhooksController::class)
        ->name('stripe.webhooks');
});

Route::middleware(['auth:sanctum', EnsureUserIsActive::class])->prefix('v1')->group(function () {
    Route::post('payments/{payment}/stripe-payment-intent', [StripePaymentIntentsController::class, 'store'])
        ->middleware('throttle:stripe-payment-intents')
        ->name('payments.stripePaymentIntent');

    Route::apiResource('payments', PaymentsController::class)
        ->except('destroy')
        ->names('payments');
});
