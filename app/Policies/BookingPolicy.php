<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\HandlesRentalAuthorization;
use Modules\Bookings\Models\Booking;

class BookingPolicy
{
    use HandlesRentalAuthorization;

    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['vendor', 'customer'], true);
    }

    public function view(User $user, Booking $booking): bool
    {
        return $booking->customer_id === $user->id
            || $this->ownsVendorProfile($user, $booking->vendor_id);
    }

    public function create(User $user): bool
    {
        return $user->role === 'customer';
    }

    public function update(User $user, Booking $booking): bool
    {
        return $this->view($user, $booking);
    }

    public function delete(User $user, Booking $booking): bool
    {
        return $this->view($user, $booking);
    }
}
