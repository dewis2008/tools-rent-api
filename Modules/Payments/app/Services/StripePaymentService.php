<?php

namespace Modules\Payments\Services;

use Illuminate\Validation\ValidationException;
use Modules\Payments\Data\PaymentRefundResult;
use Modules\Payments\Models\Payment;
use Stripe\Exception\ApiErrorException;
use Stripe\Refund;
use Stripe\StripeClient;
use UnexpectedValueException;

class StripePaymentService
{
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
                    'idempotency_key' => "payment-{$payment->id}-refund",
                ],
            );
        } catch (ApiErrorException $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'status' => __('Stripe could not process the refund. Please try again.'),
            ]);
        }

        $result = $this->refundResult($refund);

        if ($result->paymentStatus === 'refund_failed') {
            throw ValidationException::withMessages([
                'status' => __('Stripe rejected the refund request.'),
            ]);
        }

        return $result;
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
}
