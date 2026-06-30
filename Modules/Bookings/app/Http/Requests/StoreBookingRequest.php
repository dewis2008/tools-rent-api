<?php

namespace Modules\Bookings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Bookings\Models\Booking;

class StoreBookingRequest extends FormRequest
{
    public function rules(): array
    {
        $customerIdRules = $this->user()?->role === 'admin'
            ? [
                'required',
                'integer',
                Rule::exists('users', 'id')
                    ->where('role', 'customer')
                    ->where('status', 'active')
                    ->whereNotNull('email_verified_at'),
            ]
            : ['prohibited'];

        return [
            'tool_id' => ['required', 'integer', 'exists:tools,id'],
            'customer_id' => $customerIdRules,
            'vendor_id' => ['prohibited'],
            'start_at' => ['required', 'date', 'after_or_equal:now'],
            'end_at' => ['required', 'date', 'after:start_at'],
            'status' => ['prohibited'],
            'expires_at' => ['prohibited'],
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

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['start_at', 'end_at'])) {
                    return;
                }

                $startAt = Carbon::parse($this->input('start_at'));
                $endAt = Carbon::parse($this->input('end_at'));

                if ($endAt->lte($startAt->copy()->addDays(Booking::MaxRentalDays))) {
                    return;
                }

                $validator->errors()->add(
                    'end_at',
                    __('A booking cannot exceed :days rental days.', ['days' => Booking::MaxRentalDays]),
                );
            },
        ];
    }
}
