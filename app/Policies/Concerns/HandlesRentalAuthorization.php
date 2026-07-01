<?php

namespace App\Policies\Concerns;

use App\Models\User;

trait HandlesRentalAuthorization
{
    public function before(?User $user, string $ability): ?bool
    {
        if (! $user) {
            return null;
        }

        if ($user->status !== 'active') {
            return false;
        }

        if ($user->role === 'admin') {
            return true;
        }

        return null;
    }

    protected function ownsVendorProfile(User $user, ?int $vendorId): bool
    {
        if (! $vendorId) {
            return false;
        }

        return $user->vendorProfile()
            ->whereKey($vendorId)
            ->where('verification_status', 'approved')
            ->exists();
    }

    protected function hasApprovedVendorProfile(User $user): bool
    {
        return $user->vendorProfile()
            ->where('verification_status', 'approved')
            ->exists();
    }
}
