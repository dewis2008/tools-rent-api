<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\HandlesRentalAuthorization;
use Modules\ToolImages\Models\ToolImage;

class ToolImagePolicy
{
    use HandlesRentalAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(?User $user, ToolImage $toolImage): bool
    {
        if (! $toolImage->tool) {
            return false;
        }

        if (! $user) {
            return $toolImage->tool->isPubliclyAvailable();
        }

        return $user->can('view', $toolImage->tool);
    }

    public function create(User $user): bool
    {
        return $user->role === 'vendor' && $this->hasApprovedVendorProfile($user);
    }

    public function update(User $user, ToolImage $toolImage): bool
    {
        return $this->ownsVendorProfile($user, $toolImage->tool?->vendor_id);
    }

    public function delete(User $user, ToolImage $toolImage): bool
    {
        return $this->ownsVendorProfile($user, $toolImage->tool?->vendor_id);
    }
}
