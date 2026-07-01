<?php

namespace Modules\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Bookings\Models\Booking;
use Modules\Payments\Models\Payment;

class StorePaymentRequest extends FormRequest
{
    public function rules(): array
    {
        $providerPaymentIdRules = $this->user()?->role !== 'admin' && $this->input('provider', 'demo') === 'stripe'
            ? ['prohibited']
            : [
                'nullable',
                'string',
                'max:255',
                Rule::unique('payments', 'provider_payment_id')->where(
                    fn ($query) => $query->where('provider', $this->input('provider', 'demo')),
                ),
            ];

        return [
            'booking_id' => ['required', 'integer', 'exists:bookings,id', 'unique:payments,booking_id'],
            'customer_id' => ['prohibited'],
            'provider' => ['sometimes', 'required', 'in:demo,stripe,manual'],
            'provider_payment_id' => $providerPaymentIdRules,
            'status' => ['prohibited'],
            'amount' => ['prohibited'],
            'currency' => ['prohibited'],
            'paid_at' => ['prohibited'],
        ];
    }

    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user?->can('create', Payment::class)) {
            return false;
        }

        if ($user->role === 'admin') {
            return true;
        }

        $bookingId = $this->input('booking_id');

        if (! is_scalar($bookingId) || filter_var($bookingId, FILTER_VALIDATE_INT) === false) {
            return true;
        }

        $booking = Booking::query()->find((int) $bookingId);

        return ! $booking || $booking->customer_id === $user->id;
    }
}
