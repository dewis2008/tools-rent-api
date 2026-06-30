<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Bookings\Models\Booking;
use Modules\Payments\Models\Payment;
use Modules\Payments\Services\PaymentRefundService;
use Modules\Payments\Services\StripePaymentService;

class BookingPaymentStateService
{
    private const BookingStatusTransitions = [
        'pending' => ['paid', 'cancelled'],
        'paid' => ['active', 'cancelled'],
        'active' => ['completed'],
        'completed' => [],
        'cancelled' => [],
    ];

    private const PaymentStatusTransitions = [
        'pending' => ['paid', 'failed'],
        'paid' => ['refunded'],
        'failed' => ['pending'],
        'refund_pending' => [],
        'refund_failed' => ['refunded'],
        'refunded' => [],
    ];

    public function __construct(
        private PaymentRefundService $paymentRefunds,
        private StripePaymentService $stripePayments,
    ) {}

    public function transitionBooking(Booking $booking, string $status, User $user): Booking
    {
        return DB::transaction(function () use ($booking, $status, $user): Booking {
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);
            $payment = Payment::query()
                ->where('booking_id', $booking->id)
                ->lockForUpdate()
                ->first();

            if ($booking->status === $status) {
                return $booking;
            }

            $allowedStatuses = self::BookingStatusTransitions[$booking->status] ?? [];

            if (! in_array($status, $allowedStatuses, true)) {
                throw ValidationException::withMessages([
                    'status' => __("Cannot transition booking from {$booking->status} to {$status}."),
                ]);
            }

            if (! $this->canTransitionBooking($booking, $status, $user)) {
                throw ValidationException::withMessages([
                    'status' => __('You cannot perform this booking status transition.'),
                ]);
            }

            $this->ensurePaymentSupportsBookingStatus($payment, $status);

            if ($status === 'cancelled' && $payment?->status === 'paid') {
                $payment->update($this->pendingRefundUpdates($payment));
                $this->paymentRefunds->schedule($payment);
            }

            $booking->update(['status' => $status]);

            return $booking;
        });
    }

    public function transitionPayment(Payment $payment, array $validated): Payment
    {
        $this->verifyStripePayment($payment, $validated);

        return DB::transaction(function () use ($payment, $validated): Payment {
            $booking = Booking::query()->lockForUpdate()->findOrFail($payment->booking_id);
            $payment = Payment::query()
                ->where('booking_id', $booking->id)
                ->lockForUpdate()
                ->findOrFail($payment->id);
            $status = $validated['status'];

            if ($payment->status === $status) {
                $this->ensureProviderReferenceSupportsPaymentStatus($payment, $status, $validated);
                $this->synchronizeBookingWithPayment($booking, $payment);

                return $this->updateProviderReference($payment, $validated);
            }

            $allowedStatuses = self::PaymentStatusTransitions[$payment->status] ?? [];

            if (! in_array($status, $allowedStatuses, true)) {
                throw ValidationException::withMessages([
                    'status' => __("Cannot transition payment from {$payment->status} to {$status}."),
                ]);
            }

            $this->ensureBookingSupportsPaymentStatus($booking, $status);
            $this->ensureProviderReferenceSupportsPaymentStatus($payment, $status, $validated);

            $updates = [
                'status' => $status,
            ];

            $shouldScheduleRefund = in_array($payment->status, ['paid', 'refund_failed'], true)
                && $status === 'refunded';

            if ($shouldScheduleRefund) {
                $updates = $this->pendingRefundUpdates($payment);
                $status = 'refund_pending';
            }

            if (array_key_exists('provider_payment_id', $validated)) {
                $updates['provider_payment_id'] = $validated['provider_payment_id'];
            }

            if ($status === 'paid') {
                $updates['paid_at'] = now();
            }

            if ($status === 'pending') {
                $updates['paid_at'] = null;
            }

            $payment->update($updates);
            $this->synchronizeBookingWithPayment($booking, $payment);

            if ($shouldScheduleRefund) {
                $this->paymentRefunds->schedule($payment);
            }

            return $payment;
        });
    }

    private function canTransitionBooking(Booking $booking, string $status, User $user): bool
    {
        if ($user->role === 'admin') {
            return true;
        }

        if ($user->role === 'customer') {
            return $booking->customer_id === $user->id
                && $booking->status === 'pending'
                && $status === 'cancelled';
        }

        if (! $user->vendorProfile()->whereKey($booking->vendor_id)->exists()) {
            return false;
        }

        if ($status === 'active') {
            return $booking->isWithinRentalWindow(now());
        }

        if ($status === 'completed') {
            return $booking->end_at->lte(now());
        }

        return $status === 'cancelled';
    }

    private function ensurePaymentSupportsBookingStatus(?Payment $payment, string $status): void
    {
        if (! in_array($status, ['paid', 'active', 'completed'], true)) {
            return;
        }

        if ($payment?->status === 'paid') {
            return;
        }

        throw ValidationException::withMessages([
            'status' => __('A paid payment is required for this booking status transition.'),
        ]);
    }

    private function ensureBookingSupportsPaymentStatus(Booking $booking, string $status): void
    {
        if ($status === 'paid' && $booking->expires_at?->isPast()) {
            throw ValidationException::withMessages([
                'status' => __('The booking reservation has expired.'),
            ]);
        }

        if (in_array($status, ['paid', 'pending'], true) && $booking->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => __("A {$booking->status} booking cannot have a {$status} payment."),
            ]);
        }

        if ($status !== 'refunded') {
            return;
        }

        if (in_array($booking->status, ['paid', 'cancelled'], true)) {
            return;
        }

        throw ValidationException::withMessages([
            'status' => __("A {$booking->status} booking cannot be refunded."),
        ]);
    }

    private function ensureProviderReferenceSupportsPaymentStatus(
        Payment $payment,
        string $status,
        array $validated,
    ): void {
        if ($status !== 'paid' || $payment->provider !== 'stripe') {
            return;
        }

        $providerPaymentId = $validated['provider_payment_id'] ?? $payment->provider_payment_id;

        if ($providerPaymentId) {
            return;
        }

        throw ValidationException::withMessages([
            'provider_payment_id' => __('A Stripe PaymentIntent identifier is required before marking the payment as paid.'),
        ]);
    }

    private function verifyStripePayment(Payment $payment, array $validated): void
    {
        if ($validated['status'] !== 'paid' || $payment->provider !== 'stripe') {
            return;
        }

        $providerPaymentId = $validated['provider_payment_id'] ?? $payment->provider_payment_id;

        if (! $providerPaymentId) {
            throw ValidationException::withMessages([
                'provider_payment_id' => __('A Stripe PaymentIntent identifier is required before marking the payment as paid.'),
            ]);
        }

        $this->stripePayments->verifySucceededPaymentIntent($payment, $providerPaymentId);
    }

    private function synchronizeBookingWithPayment(Booking $booking, Payment $payment): void
    {
        if ($payment->status === 'paid') {
            if ($booking->status === 'pending') {
                $booking->update(['status' => 'paid']);

                return;
            }

            if (in_array($booking->status, ['paid', 'active', 'completed'], true)) {
                return;
            }

            throw ValidationException::withMessages([
                'status' => __("A {$booking->status} booking cannot have a paid payment."),
            ]);
        }

        if (! in_array($payment->status, ['refunded', 'refund_pending'], true)) {
            return;
        }

        if ($booking->status === 'paid') {
            $booking->update(['status' => 'cancelled']);

            return;
        }

        if ($booking->status === 'cancelled') {
            return;
        }

        throw ValidationException::withMessages([
            'status' => __("A {$booking->status} booking cannot have a refunded payment."),
        ]);
    }

    private function updateProviderReference(Payment $payment, array $validated): Payment
    {
        if (array_key_exists('provider_payment_id', $validated)) {
            $payment->update([
                'provider_payment_id' => $validated['provider_payment_id'],
            ]);
        }

        return $payment;
    }

    private function pendingRefundUpdates(Payment $payment): array
    {
        return [
            'status' => 'refund_pending',
            'provider_refund_id' => null,
            'refund_attempts' => $payment->refund_attempts + 1,
        ];
    }
}
