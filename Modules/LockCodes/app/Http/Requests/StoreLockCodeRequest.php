<?php

namespace Modules\LockCodes\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Bookings\Models\Booking;
use Modules\LockCodes\Models\LockCode;

class StoreLockCodeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'booking_id' => ['required', 'integer', 'exists:bookings,id', 'unique:lock_codes,booking_id'],
            'code' => ['required', 'string', 'max:20'],
            'valid_from' => ['required', 'date'],
            'valid_until' => ['required', 'date', 'after:valid_from'],
            'status' => ['sometimes', 'required', 'in:generated,sent,active,expired,revoked'],
        ];
    }

    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user?->can('create', LockCode::class)) {
            return false;
        }

        if ($user->role === 'admin') {
            return true;
        }

        $booking = Booking::query()->find($this->input('booking_id'));

        return $booking && $user->vendorProfile()->whereKey($booking->vendor_id)->exists();
    }
}
