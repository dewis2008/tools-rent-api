<?php

namespace Modules\LockCodes\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Bookings\Models\Booking;
use Modules\LockCodes\Http\Requests\Concerns\ValidatesLockCodeConfiguration;
use Modules\LockCodes\Models\LockCode;

class UpdateLockCodeRequest extends FormRequest
{
    use ValidatesLockCodeConfiguration;

    private const StatusTransitions = [
        'generated' => ['sent', 'active', 'revoked'],
        'sent' => ['active', 'revoked'],
        'active' => ['expired', 'revoked'],
        'expired' => [],
        'revoked' => [],
    ];

    public function rules(): array
    {
        return [
            'booking_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('bookings', 'id')->withoutTrashed(),
                Rule::unique('lock_codes', 'booking_id')->ignore($this->route('lockCode')),
            ],
            'code' => ['sometimes', 'required', 'string', 'max:20'],
            'valid_from' => ['sometimes', 'required', 'date'],
            'valid_until' => ['sometimes', 'required', 'date'],
            'status' => ['sometimes', 'required', 'in:generated,sent,active,expired,revoked'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $lockCode = $this->route('lockCode');

                $this->validateLifecycle($validator, $lockCode);

                if (! $this->hasAny(['booking_id', 'valid_from', 'valid_until', 'status'])) {
                    return;
                }

                if ($validator->errors()->hasAny(['booking_id', 'valid_from', 'valid_until', 'status'])) {
                    return;
                }

                $booking = $this->has('booking_id')
                    ? Booking::query()->find($this->input('booking_id'))
                    : $lockCode->booking;

                if (! $booking) {
                    return;
                }

                $validFrom = $this->has('valid_from')
                    ? Carbon::parse($this->input('valid_from'))
                    : $lockCode->valid_from;
                $validUntil = $this->has('valid_until')
                    ? Carbon::parse($this->input('valid_until'))
                    : $lockCode->valid_until;

                if (! $validUntil->gt($validFrom)) {
                    $errorField = $this->has('valid_until') ? 'valid_until' : 'valid_from';

                    $validator->errors()->add(
                        $errorField,
                        __('The lock code validity end must be after its validity start.'),
                    );

                    return;
                }

                $this->validateLockCodeConfiguration(
                    $validator,
                    $booking,
                    $validFrom,
                    $validUntil,
                    (string) $this->input('status', $lockCode->status),
                );
            },
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

        $bookingId = $this->input('booking_id');

        if (! is_scalar($bookingId) || filter_var($bookingId, FILTER_VALIDATE_INT) === false) {
            return true;
        }

        $booking = Booking::query()->find((int) $bookingId);

        return ! $booking || $user->vendorProfile()->whereKey($booking->vendor_id)->exists();
    }

    private function validateLifecycle(Validator $validator, LockCode $lockCode): void
    {
        if (in_array($lockCode->booking?->status, ['completed', 'cancelled'], true)) {
            foreach (['booking_id', 'code', 'valid_from', 'valid_until', 'status'] as $field) {
                if (! $this->has($field)) {
                    continue;
                }

                $validator->errors()->add(
                    $field,
                    __('A lock code cannot be changed after its booking closes.'),
                );
            }

            return;
        }

        if ($lockCode->status === 'active') {
            foreach (['booking_id', 'code', 'valid_from', 'valid_until'] as $field) {
                if (! $this->has($field)) {
                    continue;
                }

                $validator->errors()->add(
                    $field,
                    __('An active lock code can no longer be changed.'),
                );
            }
        }

        if (! $this->has('status') || $validator->errors()->has('status')) {
            return;
        }

        $status = (string) $this->input('status');

        if ($status === $lockCode->status) {
            return;
        }

        if (in_array($status, self::StatusTransitions[$lockCode->status] ?? [], true)) {
            return;
        }

        $validator->errors()->add(
            'status',
            __("Cannot transition lock code from {$lockCode->status} to {$status}."),
        );
    }
}
