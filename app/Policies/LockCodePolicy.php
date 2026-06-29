<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\HandlesRentalAuthorization;
use Modules\LockCodes\Models\LockCode;

class LockCodePolicy
{
    use HandlesRentalAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->role === 'customer'
            || ($user->role === 'vendor' && $this->hasApprovedVendorProfile($user));
    }

    public function view(User $user, LockCode $lockCode): bool
    {
        return $lockCode->booking?->customer_id === $user->id
            || $this->ownsVendorProfile($user, $lockCode->booking?->vendor_id);
    }

    public function reveal(User $user, LockCode $lockCode): bool
    {
        $booking = $lockCode->booking;

        if (! $booking) {
            return false;
        }

        $canAccessBooking = $booking->customer_id === $user->id
            || $this->ownsVendorProfile($user, $booking->vendor_id);

        if (! $canAccessBooking) {
            return false;
        }

        if ($lockCode->status !== 'active') {
            return false;
        }

        $now = now();

        return $booking->isRentalActiveAt($now)
            && $lockCode->valid_from->lte($now)
            && $lockCode->valid_until->gte($now);
    }

    public function create(User $user): bool
    {
        return $user->role === 'vendor' && $this->hasApprovedVendorProfile($user);
    }

    public function update(User $user, LockCode $lockCode): bool
    {
        return $this->ownsVendorProfile($user, $lockCode->booking?->vendor_id);
    }

    public function delete(User $user, LockCode $lockCode): bool
    {
        return $this->ownsVendorProfile($user, $lockCode->booking?->vendor_id);
    }
}
