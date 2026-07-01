<?php

namespace Modules\Tools\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;
use Modules\Bookings\Models\Booking;

class ToolAvailabilityRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'start_at' => ['required', 'date', 'after_or_equal:now'],
            'end_at' => ['required', 'date', 'after:start_at'],
        ];
    }

    public function authorize(): bool
    {
        return true;
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
                    __('An availability range cannot exceed :days rental days.', [
                        'days' => Booking::MaxRentalDays,
                    ]),
                );
            },
        ];
    }
}
