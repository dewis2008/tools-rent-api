<?php

namespace Modules\Bookings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBookingRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'tool_id' => ['sometimes', 'required', 'integer', 'exists:tools,id'],
            'customer_id' => ['sometimes', 'required', 'integer', 'exists:users,id'],
            'vendor_id' => ['sometimes', 'required', 'integer', 'exists:vendor_profiles,id'],
            'start_at' => ['sometimes', 'required', 'date'],
            'end_at' => ['sometimes', 'required', 'date'],
            'status' => ['sometimes', 'required', 'in:pending,paid,active,completed,cancelled'],
            'rental_price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'deposit_amount' => ['sometimes', 'required', 'numeric', 'min:0'],
            'platform_fee' => ['sometimes', 'required', 'numeric', 'min:0'],
            'vendor_amount' => ['sometimes', 'required', 'numeric', 'min:0'],
            'total_amount' => ['sometimes', 'required', 'numeric', 'min:0'],
        ];
    }

    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user?->can('update', $this->route('booking'))) {
            return false;
        }

        return $user->role === 'admin' || ! $this->hasAny(['tool_id', 'customer_id', 'vendor_id']);
    }
}
