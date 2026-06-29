<?php

namespace Modules\LockCodes\Http\Requests\Concerns;

use Carbon\CarbonInterface;
use Illuminate\Validation\Validator;
use Modules\Bookings\Models\Booking;

trait ValidatesLockCodeConfiguration
{
    protected function validateLockCodeConfiguration(
        Validator $validator,
        Booking $booking,
        CarbonInterface $validFrom,
        CarbonInterface $validUntil,
        string $status,
    ): void {
        if ($validFrom->lt($booking->start_at)) {
            $validator->errors()->add(
                'valid_from',
                __('The lock code validity must start within the booking rental window.'),
            );
        }

        if ($validUntil->gt($booking->end_at)) {
            $validator->errors()->add(
                'valid_until',
                __('The lock code validity must end within the booking rental window.'),
            );
        }

        if ($status !== 'active') {
            return;
        }

        if ($booking->isRentalActiveAt(now())) {
            return;
        }

        $validator->errors()->add(
            'status',
            __('A lock code can only be activated for an active booking during its rental window.'),
        );
    }
}
