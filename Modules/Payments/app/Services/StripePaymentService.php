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
    public function createOrRetrievePaymentIntent(Payment $payment): PaymentIntent
    {
        try {
            $paymentIntent = $payment->provider_payment_id
                ? $this->retrievePaymentIntent($payment->provider_payment_id)
                : $this->createPaymentIntentOnStripe($payment);
        } catch (ApiErrorException $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'payment' => __('Stripe could not prepare this payment. Please try again.'),
            ]);
        }

        $this->ensurePaymentIntentMatches($payment, $paymentIntent);

        return $paymentIntent;
    }

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

        $this->ensurePaymentIntentMatches($payment, $paymentIntent, requireSucceeded: true);
    }

    public function ensurePaymentIntentMatches(
        Payment $payment,
        PaymentIntent $paymentIntent,
        bool $requireSucceeded = false,
    ): void {
        $metadata = $paymentIntent->metadata?->toArray() ?? [];
        $metadataMatches = ($metadata['booking_id'] ?? null) === (string) $payment->booking_id
            && ($metadata['payment_id'] ?? null) === (string) $payment->id
            && ($metadata['customer_id'] ?? null) === (string) $payment->customer_id;
        $providerReferenceMatches = ! $payment->provider_payment_id
            || $payment->provider_payment_id === $paymentIntent->id;
        $intentAmount = $paymentIntent->amount ?? $paymentIntent->amount_received;
        $amountMatches = (int) $intentAmount === $this->amountInMinorUnits($payment);

        if ($requireSucceeded) {
            $amountMatches = $amountMatches
                && $paymentIntent->status === PaymentIntent::STATUS_SUCCEEDED
                && (int) $paymentIntent->amount_received === $this->amountInMinorUnits($payment);
        }

        if ($providerReferenceMatches
            && $metadataMatches
            && $amountMatches
            && strtolower((string) $paymentIntent->currency) === strtolower($payment->currency)) {
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

        return $this->refundResult($refund, $payment);
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

    protected function createPaymentIntentOnStripe(Payment $payment): PaymentIntent
    {
        $payment->loadMissing('customer');

        return $this->client()->paymentIntents->create(
            [
                'amount' => $this->amountInMinorUnits($payment),
                'currency' => strtolower($payment->currency),
                'automatic_payment_methods' => [
                    'enabled' => true,
                ],
                'description' => "Tool rental booking {$payment->booking_id}",
                'metadata' => [
                    'booking_id' => (string) $payment->booking_id,
                    'payment_id' => (string) $payment->id,
                    'customer_id' => (string) $payment->customer_id,
                ],
                ...($payment->customer?->email ? ['receipt_email' => $payment->customer->email] : []),
            ],
            [
                'idempotency_key' => $this->paymentIntentIdempotencyKey($payment),
            ],
        );
    }

    private function amountInMinorUnits(Payment $payment): int
    {
        return (int) round((float) $payment->amount * 100);
    }

    public function refundResult(Refund $refund, ?Payment $payment = null): PaymentRefundResult
    {
        if ($payment) {
            $metadata = $refund->metadata?->toArray() ?? [];
            $paymentIntentId = is_string($refund->payment_intent)
                ? $refund->payment_intent
                : $refund->payment_intent?->id;
            $refundMatches = ($metadata['booking_id'] ?? null) === (string) $payment->booking_id
                && ($metadata['payment_id'] ?? null) === (string) $payment->id
                && $paymentIntentId === $payment->provider_payment_id
                && (int) $refund->amount === $this->amountInMinorUnits($payment)
                && strtolower((string) $refund->currency) === strtolower($payment->currency);

            if (! $refundMatches) {
                throw ValidationException::withMessages([
                    'status' => __('The Stripe refund does not match this payment.'),
                ]);
            }
        }

        $paymentStatus = match ($refund->status) {
            Refund::STATUS_SUCCEEDED => 'refunded',
            Refund::STATUS_PENDING, Refund::STATUS_REQUIRES_ACTION => 'refund_pending',
            Refund::STATUS_FAILED, Refund::STATUS_CANCELED => 'refund_failed',
            default => throw new UnexpectedValueException("Unexpected Stripe refund status [{$refund->status}]."),
        };

        return new PaymentRefundResult($paymentStatus, $refund->id);
    }

    protected function refundIdempotencyKey(Payment $payment): string
    {
        return "payment-{$payment->id}-refund";
    }

    private function paymentIntentIdempotencyKey(Payment $payment): string
    {
        return "payment-{$payment->id}-intent-{$payment->provider_payment_attempt}";
    }
}
