<?php

namespace Modules\Payments\Services;

use Illuminate\Validation\ValidationException;
use Modules\Payments\Data\PaymentRefundResult;
use Modules\Payments\Models\Payment;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Stripe\StripeClient;
use UnexpectedValueException;

class StripePaymentService
{
    public function verifySucceededPaymentIntent(Payment $payment, string $paymentIntentId): void
    {
        try {
            $paymentIntent = $this->retrievePaymentIntent($paymentIntentId);
        } catch (ApiErrorException $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'provider_payment_id' => __('The Stripe payment could not be verified.'),
            ]);
        }

        $metadata = $paymentIntent->metadata?->toArray() ?? [];
        $metadataMatches = ($metadata['booking_id'] ?? null) === (string) $payment->booking_id
            && ($metadata['payment_id'] ?? null) === (string) $payment->id
            && ($metadata['customer_id'] ?? null) === (string) $payment->customer_id;
        $paymentMatches = $paymentIntent->status === PaymentIntent::STATUS_SUCCEEDED
            && $paymentIntent->amount_received === $this->amountInMinorUnits($payment)
            && strtolower($paymentIntent->currency) === strtolower($payment->currency)
            && $metadataMatches;

        if ($paymentMatches) {
            return;
        }

        throw ValidationException::withMessages([
            'provider_payment_id' => __('The Stripe payment does not match this booking.'),
        ]);
    }

    public function createRefund(Payment $payment): PaymentRefundResult
    {
        if (! $payment->provider_payment_id) {
            throw ValidationException::withMessages([
                'status' => __('The Stripe PaymentIntent identifier is missing.'),
            ]);
        }

        try {
            $refund = $this->client()->refunds->create(
                [
                    'payment_intent' => $payment->provider_payment_id,
                    'reason' => Refund::REASON_REQUESTED_BY_CUSTOMER,
                    'metadata' => [
                        'booking_id' => (string) $payment->booking_id,
                        'payment_id' => (string) $payment->id,
                    ],
                ],
                [
                    'idempotency_key' => $this->refundIdempotencyKey($payment),
                ],
            );
        } catch (ApiErrorException $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'status' => __('Stripe could not process the refund. Please try again.'),
            ]);
        }

        return $this->refundResult($refund);
    }

    public function retrieveRefund(string $refundId): PaymentRefundResult
    {
        try {
            $refund = $this->client()->refunds->retrieve($refundId);
        } catch (ApiErrorException $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'status' => __('Stripe refund status could not be retrieved.'),
            ]);
        }

        return $this->refundResult($refund);
    }

    private function client(): StripeClient
    {
        $secret = (string) config('services.stripe.secret');

        if ($secret === '') {
            throw ValidationException::withMessages([
                'status' => __('Stripe is not configured.'),
            ]);
        }

        return new StripeClient($secret);
    }

    protected function retrievePaymentIntent(string $paymentIntentId): PaymentIntent
    {
        return $this->client()->paymentIntents->retrieve($paymentIntentId);
    }

    private function amountInMinorUnits(Payment $payment): int
    {
        return (int) round((float) $payment->amount * 100);
    }

    private function refundResult(Refund $refund): PaymentRefundResult
    {
        $paymentStatus = match ($refund->status) {
            Refund::STATUS_SUCCEEDED => 'refunded',
            Refund::STATUS_PENDING, Refund::STATUS_REQUIRES_ACTION => 'refund_pending',
            Refund::STATUS_FAILED, Refund::STATUS_CANCELED => 'refund_failed',
            default => throw new UnexpectedValueException("Unexpected Stripe refund status [{$refund->status}]."),
        };

        return new PaymentRefundResult($paymentStatus, $refund->id);
    }

    private function refundIdempotencyKey(Payment $payment): string
    {
        $attempt = max($payment->refund_attempts, 1);

        return "payment-{$payment->id}-refund-{$attempt}";
    }
}
