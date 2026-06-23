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
        return in_array($user->role, ['vendor', 'customer'], true);
    }

    public function view(User $user, LockCode $lockCode): bool
    {
        return $lockCode->booking?->customer_id === $user->id
            || $this->ownsVendorProfile($user, $lockCode->booking?->vendor_id);
    }

    public function reveal(User $user, LockCode $lockCode): bool
    {
        if ($this->ownsVendorProfile($user, $lockCode->booking?->vendor_id)) {
            return true;
        }

        if ($lockCode->booking?->customer_id !== $user->id) {
            return false;
        }

        if ($lockCode->status !== 'active') {
            return false;
        }

        $now = now();

        return $lockCode->valid_from->lte($now)
            && $lockCode->valid_until->gte($now);
    }

    public function create(User $user): bool
    {
        return $user->role === 'vendor' && $user->vendorProfile()->exists();
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
