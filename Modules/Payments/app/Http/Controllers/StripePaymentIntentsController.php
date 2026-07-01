<?php

namespace Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Payments\Models\Payment;
use Modules\Payments\Services\StripePaymentIntentService;

class StripePaymentIntentsController extends Controller
{
    public function store(Payment $payment, StripePaymentIntentService $paymentIntents): JsonResponse
    {
        $this->authorize('createPaymentIntent', $payment);

        $result = $paymentIntents->create($payment);

        return response()
            ->json([
                'payment' => $result->payment->load(['booking', 'customer']),
                'client_secret' => $result->clientSecret,
            ])
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache');
    }
}
