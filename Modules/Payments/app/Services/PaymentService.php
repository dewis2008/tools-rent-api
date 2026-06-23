<?php

namespace Modules\Payments\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Bookings\Models\Booking;
use Modules\Payments\Models\Payment;

class PaymentService
{
    private const StatusTransitions = [
        'pending' => ['paid', 'failed'],
        'paid' => ['refunded'],
        'failed' => ['pending'],
        'refunded' => [],
    ];

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
        return DB::transaction(function () use ($payment, $validated): Payment {
            $payment = Payment::query()->with('booking')->lockForUpdate()->findOrFail($payment->id);
            $status = $validated['status'];

            if ($payment->status === $status) {
                return $this->updateProviderReference($payment, $validated);
            }

            $allowedStatuses = self::StatusTransitions[$payment->status] ?? [];

            if (! in_array($status, $allowedStatuses, true)) {
                throw ValidationException::withMessages([
                    'status' => __("Cannot transition payment from {$payment->status} to {$status}."),
                ]);
            }

            if ($status === 'paid' && $payment->booking->status !== 'pending') {
                throw ValidationException::withMessages([
                    'status' => __('Only pending bookings can be marked as paid.'),
                ]);
            }

            $updates = [
                'status' => $status,
            ];

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

            if ($status === 'paid') {
                $payment->booking->update(['status' => 'paid']);
            }

            return $payment;
        });
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
}
