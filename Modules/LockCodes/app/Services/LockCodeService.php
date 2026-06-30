<?php

namespace Modules\LockCodes\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Bookings\Models\Booking;
use Modules\LockCodes\Models\LockCode;

class LockCodeService
{
    private const StatusTransitions = [
        'generated' => ['sent', 'active', 'revoked'],
        'sent' => ['active', 'revoked'],
        'active' => ['expired', 'revoked'],
        'expired' => [],
        'revoked' => [],
    ];

    public function update(LockCode $lockCode, array $attributes, User $user): LockCode
    {
        return DB::transaction(function () use ($lockCode, $attributes, $user): LockCode {
            $lockCode = LockCode::query()->lockForUpdate()->findOrFail($lockCode->id);
            [$currentBooking, $targetBooking] = $this->lockBookings($lockCode, $attributes);

            $lockCode->setRelation('booking', $currentBooking);
            $this->authorizeUpdate($lockCode, $targetBooking, $user);
            $this->validateLifecycle($lockCode, $currentBooking, $attributes);
            $this->validateConfiguration($lockCode, $targetBooking, $attributes);

            $lockCode->update($attributes);

            return $lockCode;
        });
    }

    public function revoke(LockCode $lockCode, User $user): void
    {
        DB::transaction(function () use ($lockCode, $user): void {
            $lockCode = LockCode::query()->lockForUpdate()->findOrFail($lockCode->id);
            $booking = Booking::withTrashed()->lockForUpdate()->findOrFail($lockCode->booking_id);

            $lockCode->setRelation('booking', $booking);

            if (! $user->can('delete', $lockCode)) {
                throw new AuthorizationException;
            }

            $lockCode->update(['status' => 'revoked']);
        });
    }

    /** @return array{Booking, Booking} */
    private function lockBookings(LockCode $lockCode, array $attributes): array
    {
        $targetBookingId = (int) ($attributes['booking_id'] ?? $lockCode->booking_id);
        $bookingIds = collect([$lockCode->booking_id, $targetBookingId])
            ->unique()
            ->sort()
            ->values()
            ->all();
        $bookings = Booking::withTrashed()
            ->whereKey($bookingIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        return [
            $bookings->get($lockCode->booking_id) ?? throw new AuthorizationException,
            $bookings->get($targetBookingId) ?? throw new AuthorizationException,
        ];
    }

    private function authorizeUpdate(LockCode $lockCode, Booking $targetBooking, User $user): void
    {
        if (! $user->can('update', $lockCode)) {
            throw new AuthorizationException;
        }

        if ($user->role === 'admin') {
            return;
        }

        $ownsTargetBooking = $user->vendorProfile()
            ->whereKey($targetBooking->vendor_id)
            ->where('verification_status', 'approved')
            ->exists();

        if (! $ownsTargetBooking) {
            throw new AuthorizationException;
        }
    }

    private function validateLifecycle(LockCode $lockCode, Booking $booking, array $attributes): void
    {
        if (in_array($booking->status, ['completed', 'cancelled'], true) && $attributes !== []) {
            $errors = [];

            foreach (array_keys($attributes) as $field) {
                $errors[$field] = __('A lock code cannot be changed after its booking closes.');
            }

            throw ValidationException::withMessages($errors);
        }

        if ($lockCode->status === 'active') {
            $immutableFields = array_intersect(
                ['booking_id', 'code', 'valid_from', 'valid_until'],
                array_keys($attributes),
            );

            if ($immutableFields !== []) {
                throw ValidationException::withMessages(array_fill_keys(
                    $immutableFields,
                    __('An active lock code can no longer be changed.'),
                ));
            }
        }

        if (! array_key_exists('status', $attributes) || $attributes['status'] === $lockCode->status) {
            return;
        }

        if (in_array($attributes['status'], self::StatusTransitions[$lockCode->status] ?? [], true)) {
            return;
        }

        throw ValidationException::withMessages([
            'status' => __("Cannot transition lock code from {$lockCode->status} to {$attributes['status']}."),
        ]);
    }

    private function validateConfiguration(LockCode $lockCode, Booking $booking, array $attributes): void
    {
        $validFrom = array_key_exists('valid_from', $attributes)
            ? Carbon::parse($attributes['valid_from'])
            : $lockCode->valid_from;
        $validUntil = array_key_exists('valid_until', $attributes)
            ? Carbon::parse($attributes['valid_until'])
            : $lockCode->valid_until;
        $status = $attributes['status'] ?? $lockCode->status;
        $errors = [];

        if (! $validUntil->gt($validFrom)) {
            $errorField = array_key_exists('valid_until', $attributes) ? 'valid_until' : 'valid_from';
            $errors[$errorField] = __('The lock code validity end must be after its validity start.');
        }

        if ($validFrom->lt($booking->start_at)) {
            $errors['valid_from'] = __('The lock code validity must start within the booking rental window.');
        }

        if ($validUntil->gt($booking->end_at)) {
            $errors['valid_until'] = __('The lock code validity must end within the booking rental window.');
        }

        if ($status === 'active' && ! $booking->isRentalActiveAt(now())) {
            $errors['status'] = __('A lock code can only be activated for an active booking during its rental window.');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
