<?php

return [
    'name' => 'Bookings',
    'pending_expiration_minutes' => max((int) env('PENDING_BOOKING_EXPIRATION_MINUTES', 15), 1),
    'max_pending_per_customer' => max((int) env('MAX_PENDING_BOOKINGS_PER_CUSTOMER', 5), 1),
];
