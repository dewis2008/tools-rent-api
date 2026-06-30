<?php

namespace Modules\Bookings\Services;

use App\Models\User;
use App\Services\BookingPaymentStateService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Bookings\Models\Booking;
use Modules\Tools\Models\Tool;

class BookingService
{
    private const BlockingStatuses = ['pending', 'paid', 'active'];

    public function __construct(
        private BookingPaymentStateService $bookingPaymentStates,
    ) {}

    public function create(array $validated, User $user): Booking
    {
        return DB::transaction(function () use ($validated, $user): Booking {
            $tool = Tool::query()->lockForUpdate()->findOrFail($validated['tool_id']);

            $this->ensureToolIsActive($tool);
            $this->ensureVendorIsEligible($tool);

            $startAt = Carbon::parse($validated['start_at']);
            $endAt = Carbon::parse($validated['end_at']);

            $this->ensureRentalDurationIsAllowed($startAt, $endAt);
            $this->ensureToolIsAvailable($tool->id, $startAt, $endAt);

            $rentalDays = $this->rentalDays($startAt, $endAt);
            $rentalPrice = round((float) $tool->price_per_day * $rentalDays, 2);
            $depositAmount = round((float) $tool->deposit_amount, 2);
            $platformFee = round($rentalPrice * 0.10, 2);
            $vendorAmount = round($rentalPrice - $platformFee, 2);
            $amounts = [
                'rental_price' => $rentalPrice,
                'deposit_amount' => $depositAmount,
                'platform_fee' => $platformFee,
                'vendor_amount' => $vendorAmount,
                'total_amount' => round($rentalPrice + $depositAmount, 2),
            ];

            $this->ensureAmountsFitSchema($amounts);

            return Booking::create([
                'tool_id' => $tool->id,
                'customer_id' => $this->customerId($validated, $user),
                'vendor_id' => $tool->vendor_id,
                'start_at' => $startAt,
                'end_at' => $endAt,
                'status' => 'pending',
                ...$amounts,
            ]);
        });
    }

    public function transition(Booking $booking, string $status, User $user): Booking
    {
        return $this->bookingPaymentStates->transitionBooking($booking, $status, $user);
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

    private function ensureRentalDurationIsAllowed(Carbon $startAt, Carbon $endAt): void
    {
        if ($endAt->gt($startAt) && $endAt->lte($startAt->copy()->addDays(Booking::MaxRentalDays))) {
            return;
        }

        throw ValidationException::withMessages([
            'end_at' => __('A booking must end after it starts and cannot exceed :days rental days.', [
                'days' => Booking::MaxRentalDays,
            ]),
        ]);
    }

    private function ensureAmountsFitSchema(array $amounts): void
    {
        foreach ($amounts as $amount) {
            if (is_finite($amount) && $amount >= 0 && $amount <= Booking::MaxMoneyAmount) {
                continue;
            }

            throw ValidationException::withMessages([
                'tool_id' => __('The selected tool price exceeds the supported booking total.'),
            ]);
        }
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

    private function ensureVendorIsEligible(Tool $tool): void
    {
        if ($tool->vendor()->eligibleForRentals()->exists()) {
            return;
        }

        throw ValidationException::withMessages([
            'tool_id' => __('The selected tool is not available for booking.'),
        ]);
    }
}
