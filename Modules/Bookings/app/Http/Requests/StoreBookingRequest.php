<?php

namespace Modules\Bookings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Bookings\Models\Booking;

class StoreBookingRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'tool_id' => ['required', 'integer', 'exists:tools,id'],
            'customer_id' => ['required', 'integer', 'exists:users,id'],
            'vendor_id' => ['required', 'integer', 'exists:vendor_profiles,id'],
            'start_at' => ['required', 'date'],
            'end_at' => ['required', 'date', 'after:start_at'],
            'status' => ['sometimes', 'required', 'in:pending,paid,active,completed,cancelled'],
            'rental_price' => ['required', 'numeric', 'min:0'],
            'deposit_amount' => ['sometimes', 'required', 'numeric', 'min:0'],
            'platform_fee' => ['sometimes', 'required', 'numeric', 'min:0'],
            'vendor_amount' => ['sometimes', 'required', 'numeric', 'min:0'],
            'total_amount' => ['required', 'numeric', 'min:0'],
        ];
    }

    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user?->can('create', Booking::class)) {
            return false;
        }

        if ($user->role === 'admin') {
            return true;
        }

        if ($user->role === 'customer') {
            return (int) $this->input('customer_id') === $user->id;
        }

        return (int) $this->input('vendor_id') === $user->vendorProfile?->id;
    }
}
