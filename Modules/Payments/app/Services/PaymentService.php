<?php

namespace Modules\Payments\Services;

use App\Models\User;
use App\Services\BookingPaymentStateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Bookings\Models\Booking;
use Modules\Payments\Models\Payment;

class PaymentService
{
    public function __construct(
        private BookingPaymentStateService $bookingPaymentStates,
    ) {}

    public function create(array $validated, User $user): Payment
    {
        return DB::transaction(function () use ($validated, $user): Payment {
            $booking = Booking::query()->lockForUpdate()->findOrFail($validated['booking_id']);

            if ($user->role !== 'admin' && $booking->customer_id !== $user->id) {
                throw ValidationException::withMessages([
                    'booking_id' => __('You cannot pay another customer booking.'),
                ]);
            }

            if ($booking->status !== 'pending') {
                throw ValidationException::withMessages([
                    'booking_id' => __('Only pending bookings can be paid.'),
                ]);
            }

            if ($booking->expires_at?->isPast()) {
                throw ValidationException::withMessages([
                    'booking_id' => __('This booking reservation has expired.'),
                ]);
            }

            if ($booking->payment()->exists()) {
                throw ValidationException::withMessages([
                    'booking_id' => __('This booking already has a payment.'),
                ]);
            }

            return Payment::create([
                'booking_id' => $booking->id,
                'customer_id' => $booking->customer_id,
                'provider' => $validated['provider'] ?? 'demo',
                'provider_payment_id' => $validated['provider_payment_id'] ?? null,
                'status' => 'pending',
                'amount' => $booking->total_amount,
                'currency' => 'EUR',
            ]);
        });
    }

    public function transition(Payment $payment, array $validated): Payment
    {
        return $this->bookingPaymentStates->transitionPayment($payment, $validated);
    }
}
