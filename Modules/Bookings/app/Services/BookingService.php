<?php

namespace Modules\Bookings\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Bookings\Models\Booking;
use Modules\Tools\Models\Tool;

class BookingService
{
    private const BlockingStatuses = ['pending', 'paid', 'active'];

    private const StatusTransitions = [
        'pending' => ['paid', 'cancelled'],
        'paid' => ['active', 'cancelled'],
        'active' => ['completed'],
        'completed' => [],
        'cancelled' => [],
    ];

    public function create(array $validated, User $user): Booking
    {
        return DB::transaction(function () use ($validated, $user): Booking {
            $tool = Tool::query()->lockForUpdate()->findOrFail($validated['tool_id']);

            $this->ensureToolIsActive($tool);

            $startAt = Carbon::parse($validated['start_at']);
            $endAt = Carbon::parse($validated['end_at']);

            $this->ensureToolIsAvailable($tool->id, $startAt, $endAt);

            $rentalDays = $this->rentalDays($startAt, $endAt);
            $rentalPrice = round((float) $tool->price_per_day * $rentalDays, 2);
            $depositAmount = round((float) $tool->deposit_amount, 2);
            $platformFee = round($rentalPrice * 0.10, 2);
            $vendorAmount = round($rentalPrice - $platformFee, 2);

            return Booking::create([
                'tool_id' => $tool->id,
                'customer_id' => $this->customerId($validated, $user),
                'vendor_id' => $tool->vendor_id,
                'start_at' => $startAt,
                'end_at' => $endAt,
                'status' => 'pending',
                'rental_price' => $rentalPrice,
                'deposit_amount' => $depositAmount,
                'platform_fee' => $platformFee,
                'vendor_amount' => $vendorAmount,
                'total_amount' => round($rentalPrice + $depositAmount, 2),
            ]);
        });
    }

    public function transition(Booking $booking, string $status, User $user): Booking
    {
        return DB::transaction(function () use ($booking, $status, $user): Booking {
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);

            if ($booking->status === $status) {
                return $booking;
            }

            $allowedStatuses = self::StatusTransitions[$booking->status] ?? [];

            if (! in_array($status, $allowedStatuses, true)) {
                throw ValidationException::withMessages([
                    'status' => __("Cannot transition booking from {$booking->status} to {$status}."),
                ]);
            }

            if (! $this->canTransition($booking, $status, $user)) {
                throw ValidationException::withMessages([
                    'status' => __('You cannot perform this booking status transition.'),
                ]);
            }

            $booking->update(['status' => $status]);

            return $booking;
        });
    }

    public function delete(Booking $booking): void
    {
        DB::transaction(function () use ($booking): void {
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);

            if (! $booking->isSafelyDeletable()) {
                throw new AuthorizationException(__('Only pending bookings without payment or lock-code records can be deleted.'));
            }

            $booking->delete();
        });
    }

    private function customerId(array $validated, User $user): int
    {
        if ($user->role === 'admin') {
            return (int) $validated['customer_id'];
        }

        return $user->id;
    }

    private function rentalDays(Carbon $startAt, Carbon $endAt): int
    {
        $hours = $startAt->diffInHours($endAt);

        return max(1, (int) ceil($hours / 24));
    }

    private function ensureToolIsActive(Tool $tool): void
    {
        if ($tool->status === 'active') {
            return;
        }

        throw ValidationException::withMessages([
            'tool_id' => __('The selected tool is not available for booking.'),
        ]);
    }

    private function ensureToolIsAvailable(int $toolId, Carbon $startAt, Carbon $endAt): void
    {
        $hasOverlap = Booking::query()
            ->where('tool_id', $toolId)
            ->whereIn('status', self::BlockingStatuses)
            ->where('start_at', '<', $endAt)
            ->where('end_at', '>', $startAt)
            ->lockForUpdate()
            ->exists();

        if ($hasOverlap) {
            throw ValidationException::withMessages([
                'start_at' => __('The selected tool is not available for this period.'),
            ]);
        }
    }

    private function canTransition(Booking $booking, string $status, User $user): bool
    {
        if ($user->role === 'admin') {
            return true;
        }

        if ($user->role === 'customer') {
            return $booking->customer_id === $user->id
                && $booking->status === 'pending'
                && $status === 'cancelled';
        }

        return $user->vendorProfile()->whereKey($booking->vendor_id)->exists()
            && in_array($status, ['active', 'completed', 'cancelled'], true);
    }
}
