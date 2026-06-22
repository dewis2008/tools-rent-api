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
        return true;
    }

    public function create(User $user): bool
    {
        return $user->role === 'vendor' && $user->vendorProfile()->exists();
    }

    public function update(User $user, Tool $tool): bool
    {
        return $this->ownsVendorProfile($user, $tool->vendor_id);
    }

    public function delete(User $user, Tool $tool): bool
    {
        return $this->ownsVendorProfile($user, $tool->vendor_id);
    }
}
