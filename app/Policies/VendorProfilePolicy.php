<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\HandlesRentalAuthorization;
use Modules\Vendors\Models\VendorProfile;

class VendorProfilePolicy
{
    use HandlesRentalAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, VendorProfile $vendorProfile): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->role === 'vendor' && ! $user->vendorProfile()->exists();
    }

    public function update(User $user, VendorProfile $vendorProfile): bool
    {
        return $vendorProfile->user_id === $user->id;
    }

    public function delete(User $user, VendorProfile $vendorProfile): bool
    {
        return $vendorProfile->user_id === $user->id;
    }
}
