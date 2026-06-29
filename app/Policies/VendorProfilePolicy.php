<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\HandlesRentalAuthorization;
use Modules\Vendors\Models\VendorProfile;

class VendorProfilePolicy
{
    use HandlesRentalAuthorization;

    public function before(User $user, string $ability): ?bool
    {
        if (! $user->hasVerifiedEmail() || $user->status === 'blocked') {
            return false;
        }

        if ($user->role === 'admin') {
            return $user->status === 'active';
        }

        if (! in_array($user->status, ['active', 'pending'], true)) {
            return false;
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        return $user->role === 'vendor';
    }

    public function view(User $user, VendorProfile $vendorProfile): bool
    {
        return $this->ownsProfile($user, $vendorProfile);
    }

    public function create(User $user): bool
    {
        return $user->role === 'vendor'
            && ! $user->vendorProfile()->exists();
    }

    public function update(User $user, VendorProfile $vendorProfile): bool
    {
        return $this->ownsProfile($user, $vendorProfile);
    }

    public function delete(User $user, VendorProfile $vendorProfile): bool
    {
        return $this->ownsProfile($user, $vendorProfile)
            && ! $vendorProfile->hasBookingHistory();
    }

    private function ownsProfile(User $user, VendorProfile $vendorProfile): bool
    {
        return $user->role === 'vendor'
            && $vendorProfile->user_id === $user->id;
    }
}
