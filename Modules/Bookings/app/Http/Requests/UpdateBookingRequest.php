<?php

namespace Modules\Bookings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBookingRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'tool_id' => ['prohibited'],
            'customer_id' => ['prohibited'],
            'vendor_id' => ['prohibited'],
            'start_at' => ['prohibited'],
            'end_at' => ['prohibited'],
            'status' => ['required', 'in:paid,active,completed,cancelled'],
            'rental_price' => ['prohibited'],
            'deposit_amount' => ['prohibited'],
            'platform_fee' => ['prohibited'],
            'vendor_amount' => ['prohibited'],
            'total_amount' => ['prohibited'],
        ];
    }

    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user?->can('update', $this->route('booking'))) {
            return false;
        }

        return true;
    }
}
