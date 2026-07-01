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
        return $user->role === 'customer'
            || ($user->role === 'vendor' && $this->hasApprovedVendorProfile($user));
    }

    public function view(User $user, Payment $payment): bool
    {
        return $payment->customer_id === $user->id
            || $this->ownsVendorProfile($user, $payment->booking?->vendor_id);
    }

    public function create(User $user): bool
    {
        return $user->role === 'customer';
    }

    public function update(User $user, Payment $payment): bool
    {
        return false;
    }

    public function createPaymentIntent(User $user, Payment $payment): bool
    {
        return $user->role === 'customer'
            && $payment->customer_id === $user->id
            && $payment->provider === 'stripe';
    }

    public function delete(User $user, Payment $payment): bool
    {
        return false;
    }
}
