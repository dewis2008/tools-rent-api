<?php

namespace Modules\Bookings\Console;

use Illuminate\Console\Command;
use Modules\Bookings\Services\BookingService;

class ExpirePendingBookingsCommand extends Command
{
    protected $signature = 'bookings:expire-pending';

    protected $description = 'Cancel pending bookings whose payment window has expired';

    public function handle(BookingService $bookings): int
    {
        $expiredCount = $bookings->expirePending();

        $this->comment("Expired {$expiredCount} pending bookings.");

        return self::SUCCESS;
    }
}
