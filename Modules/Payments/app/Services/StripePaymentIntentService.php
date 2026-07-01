<?php

namespace Modules\Payments\Services;

use App\Services\BookingPaymentStateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Bookings\Models\Booking;
use Modules\Payments\Data\StripePaymentIntentResult;
use Modules\Payments\Models\Payment;
use Stripe\Event;
use Stripe\PaymentIntent;

class StripePaymentIntentService
{
    public function __construct(
        private StripePaymentService $stripe,
        private BookingPaymentStateService $bookingPaymentStates,
    ) {}

    public function create(Payment $payment): StripePaymentIntentResult
    {
        $payment = $this->lockPayableStripePayment($payment);
        $paymentIntent = $this->stripe->createOrRetrievePaymentIntent($payment);

        if ($paymentIntent->status === PaymentIntent::STATUS_CANCELED) {
            $payment = $this->releaseCanceledPaymentIntent($payment, $paymentIntent);
            $paymentIntent = $this->stripe->createOrRetrievePaymentIntent($payment);
        }

        if ($paymentIntent->status === PaymentIntent::STATUS_CANCELED) {
            throw ValidationException::withMessages([
                'payment' => __('Stripe could not prepare a confirmable payment. Please try again.'),
            ]);
        }

        $payment = $this->registerPaymentIntent($payment, $paymentIntent);

        if ($paymentIntent->status === PaymentIntent::STATUS_SUCCEEDED) {
            $payment = $this->bookingPaymentStates
                ->synchronizeStripePaymentIntent($paymentIntent, Event::PAYMENT_INTENT_SUCCEEDED)
                ?? $payment;
        }

        $clientSecret = (string) $paymentIntent->client_secret;

        if ($clientSecret === '') {
            throw ValidationException::withMessages([
                'payment' => __('Stripe did not return a client secret for this payment.'),
            ]);
        }

        return new StripePaymentIntentResult($payment->refresh(), $clientSecret);
    }

    private function lockPayableStripePayment(Payment $payment): Payment
    {
        return DB::transaction(function () use ($payment): Payment {
            $booking = Booking::query()->lockForUpdate()->findOrFail($payment->booking_id);
            $payment = Payment::query()
                ->where('booking_id', $booking->id)
                ->lockForUpdate()
                ->findOrFail($payment->id);

            $this->ensurePaymentCanBePrepared($payment, $booking);

            return $payment;
        });
    }

    private function registerPaymentIntent(Payment $payment, PaymentIntent $paymentIntent): Payment
    {
        return DB::transaction(function () use ($payment, $paymentIntent): Payment {
            $booking = Booking::query()->lockForUpdate()->findOrFail($payment->booking_id);
            $payment = Payment::query()
                ->where('booking_id', $booking->id)
                ->lockForUpdate()
                ->findOrFail($payment->id);

            if ($paymentIntent->status !== PaymentIntent::STATUS_SUCCEEDED) {
                $this->ensurePaymentCanBePrepared($payment, $booking);
            }

            $this->stripe->ensurePaymentIntentMatches($payment, $paymentIntent);

            if ($payment->provider_payment_id && $payment->provider_payment_id !== $paymentIntent->id) {
                throw ValidationException::withMessages([
                    'provider_payment_id' => __('The payment already has a different Stripe PaymentIntent.'),
                ]);
            }

            $payment->update([
                'provider_payment_id' => $paymentIntent->id,
                ...($payment->status === 'failed' ? ['status' => 'pending'] : []),
            ]);

            return $payment;
        });
    }

    private function releaseCanceledPaymentIntent(Payment $payment, PaymentIntent $paymentIntent): Payment
    {
        $providerPaymentAttempt = $payment->provider_payment_attempt;

        return DB::transaction(function () use ($payment, $paymentIntent, $providerPaymentAttempt): Payment {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            $this->stripe->ensurePaymentIntentMatches($payment, $paymentIntent);

            if (! in_array($payment->status, ['pending', 'failed'], true)) {
                throw ValidationException::withMessages([
                    'payment' => __('This payment can no longer be confirmed.'),
                ]);
            }

            if (! $payment->provider_payment_id
                && $payment->provider_payment_attempt !== $providerPaymentAttempt) {
                return $payment;
            }

            if ($payment->provider_payment_id
                && $payment->provider_payment_id !== $paymentIntent->id) {
                return $payment;
            }

            $payment->update([
                'provider_payment_id' => null,
                'provider_payment_attempt' => $payment->provider_payment_attempt + 1,
                'status' => 'pending',
            ]);

            return $payment;
        });
    }

    private function ensurePaymentCanBePrepared(Payment $payment, Booking $booking): void
    {
        if ($payment->provider !== 'stripe') {
            throw ValidationException::withMessages([
                'payment' => __('Only Stripe payments can create a PaymentIntent.'),
            ]);
        }

        if (! in_array($payment->status, ['pending', 'failed'], true)) {
            throw ValidationException::withMessages([
                'payment' => __('This payment can no longer be confirmed.'),
            ]);
        }

        if ($booking->status !== 'pending' || $booking->expires_at?->isPast()) {
            throw ValidationException::withMessages([
                'payment' => __('The booking reservation is no longer payable.'),
            ]);
        }
    }
}
