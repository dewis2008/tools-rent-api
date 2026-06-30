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
            $customer = $this->customer($validated, $user);

            $this->ensureCustomerMayBook($customer->id);

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
                'customer_id' => $customer->id,
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

    public function expirePending(): int
    {
        return Booking::query()
            ->where('status', 'pending')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'cancelled']);
    }

    private function customer(array $validated, User $user): User
    {
        $customerId = $user->role === 'admin'
            ? (int) $validated['customer_id']
            : $user->id;
        $customer = User::query()->lockForUpdate()->findOrFail($customerId);

        if ($customer->role === 'customer'
            && $customer->status === 'active'
            && $customer->hasVerifiedEmail()) {
            return $customer;
        }

        throw ValidationException::withMessages([
            'customer_id' => __('The selected customer is not eligible to make bookings.'),
        ]);
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
            ->where(function ($query): void {
                $query
                    ->whereIn('status', array_diff(self::BlockingStatuses, ['pending']))
                    ->orWhere(function ($query): void {
                        $query
                            ->where('status', 'pending')
                            ->where(function ($query): void {
                                $query
                                    ->whereNull('expires_at')
                                    ->orWhere('expires_at', '>', now());
                            });
                    });
            })
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

    private function ensureCustomerMayBook(int $customerId): void
    {
        $pendingBookings = Booking::query()
            ->where('customer_id', $customerId)
            ->where('status', 'pending')
            ->where(function ($query): void {
                $query
                    ->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->count();

        if ($pendingBookings < (int) config('bookings.max_pending_per_customer')) {
            return;
        }

        throw ValidationException::withMessages([
            'tool_id' => __('You have reached the maximum number of pending bookings.'),
        ]);
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
