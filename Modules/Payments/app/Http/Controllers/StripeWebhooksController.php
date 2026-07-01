<?php

namespace Modules\Payments\Http\Controllers;

use App\Enums\ApiErrorCode;
use Illuminate\Http\Request;
use Modules\Payments\Services\StripeWebhookService;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use Symfony\Component\HttpFoundation\Response;
use UnexpectedValueException;

class StripeWebhooksController
{
    public function __invoke(Request $request, StripeWebhookService $webhooks): Response
    {
        $webhookSecret = (string) config('services.stripe.webhook_secret');

        if ($webhookSecret === '') {
            return response()->json([
                'code' => ApiErrorCode::ServiceUnavailable->value,
                'message' => __('Stripe webhooks are not configured.'),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature'),
                $webhookSecret,
            );
        } catch (SignatureVerificationException|UnexpectedValueException) {
            return response()->json([
                'code' => ApiErrorCode::InvalidWebhookSignature->value,
                'message' => __('The Stripe webhook signature is invalid.'),
            ], Response::HTTP_BAD_REQUEST);
        }

        $webhooks->handle($event);

        return response()->noContent();
    }
}
