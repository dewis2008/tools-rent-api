<?php

namespace Modules\LockCodes\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Bookings\Models\Booking;
use Modules\LockCodes\Http\Requests\Concerns\ValidatesLockCodeConfiguration;
use Modules\LockCodes\Models\LockCode;

class StoreLockCodeRequest extends FormRequest
{
    use ValidatesLockCodeConfiguration;

    public function rules(): array
    {
        return [
            'booking_id' => [
                'required',
                'integer',
                Rule::exists('bookings', 'id')->withoutTrashed(),
                'unique:lock_codes,booking_id',
            ],
            'code' => ['required', 'string', 'max:20'],
            'valid_from' => ['required', 'date'],
            'valid_until' => ['required', 'date', 'after:valid_from'],
            'status' => ['sometimes', 'required', 'in:generated,sent,active,expired,revoked'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['booking_id', 'valid_from', 'valid_until', 'status'])) {
                    return;
                }

                $booking = Booking::query()->find($this->input('booking_id'));

                if (! $booking) {
                    return;
                }

                $this->validateLockCodeConfiguration(
                    $validator,
                    $booking,
                    Carbon::parse($this->input('valid_from')),
                    Carbon::parse($this->input('valid_until')),
                    (string) $this->input('status', 'generated'),
                );
            },
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

        $bookingId = $this->input('booking_id');

        if (! is_scalar($bookingId) || filter_var($bookingId, FILTER_VALIDATE_INT) === false) {
            return true;
        }

        $booking = Booking::query()->find((int) $bookingId);

        return ! $booking || $user->vendorProfile()->whereKey($booking->vendor_id)->exists();
    }
}
