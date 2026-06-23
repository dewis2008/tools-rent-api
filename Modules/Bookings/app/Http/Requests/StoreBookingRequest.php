<?php

namespace Modules\Bookings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Bookings\Models\Booking;

class StoreBookingRequest extends FormRequest
{
    public function rules(): array
    {
        $customerIdRules = $this->user()?->role === 'admin'
            ? ['required', 'integer', 'exists:users,id']
            : ['prohibited'];

        return [
            'tool_id' => ['required', 'integer', 'exists:tools,id'],
            'customer_id' => $customerIdRules,
            'vendor_id' => ['prohibited'],
            'start_at' => ['required', 'date', 'after_or_equal:now'],
            'end_at' => ['required', 'date', 'after:start_at'],
            'status' => ['prohibited'],
            'rental_price' => ['prohibited'],
            'deposit_amount' => ['prohibited'],
            'platform_fee' => ['prohibited'],
            'vendor_amount' => ['prohibited'],
            'total_amount' => ['prohibited'],
        ];
    }

    public function authorize(): bool
    {
        return $this->user()?->can('create', Booking::class) ?? false;
    }
}
