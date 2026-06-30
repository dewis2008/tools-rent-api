<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\HandlesRentalAuthorization;
use Modules\Tools\Models\Tool;

class ToolPolicy
{
    use HandlesRentalAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Tool $tool): bool
    {
        if ($tool->status === 'active' && $tool->vendor()->eligibleForRentals()->exists()) {
            return true;
        }

        if ($user->role !== 'vendor') {
            return false;
        }

        return $this->ownsVendorProfile($user, $tool->vendor_id);
    }

    public function create(User $user): bool
    {
        return $user->role === 'vendor' && $this->hasApprovedVendorProfile($user);
    }

    public function update(User $user, Tool $tool): bool
    {
        return $this->ownsVendorProfile($user, $tool->vendor_id);
    }

    public function delete(User $user, Tool $tool): bool
    {
        return $this->ownsVendorProfile($user, $tool->vendor_id)
            && ! $tool->hasBookingHistory();
    }
}
