<?php

namespace Modules\LockCodes\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Bookings\Models\Booking;

class UpdateLockCodeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'booking_id' => [
                'sometimes',
                'required',
                'integer',
                'exists:bookings,id',
                Rule::unique('lock_codes', 'booking_id')->ignore($this->route('lockCode')),
            ],
            'code' => ['sometimes', 'required', 'string', 'max:20'],
            'valid_from' => ['sometimes', 'required', 'date'],
            'valid_until' => ['sometimes', 'required', 'date'],
            'status' => ['sometimes', 'required', 'in:generated,sent,active,expired,revoked'],
        ];
    }

    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user?->can('update', $this->route('lockCode'))) {
            return false;
        }

        if ($user->role === 'admin' || ! $this->has('booking_id')) {
            return true;
        }

        $booking = Booking::query()->find($this->input('booking_id'));

        return $booking && $user->vendorProfile()->whereKey($booking->vendor_id)->exists();
    }
}
