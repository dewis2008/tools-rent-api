<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\HandlesRentalAuthorization;
use Modules\Payments\Models\Payment;

class PaymentPolicy
{
    use HandlesRentalAuthorization;

    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['vendor', 'customer'], true);
    }

    public function view(User $user, Payment $payment): bool
    {
        return $payment->customer_id === $user->id
            || $this->ownsVendorProfile($user, $payment->booking?->vendor_id);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Payment $payment): bool
    {
        return false;
    }

    public function delete(User $user, Payment $payment): bool
    {
        return false;
    }
}
