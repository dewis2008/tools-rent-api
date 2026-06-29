<?php

namespace Modules\LockCodes\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Bookings\Models\Booking;
use Modules\LockCodes\Models\LockCode;

class LockCodesDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        Booking::query()
            ->whereIn('status', ['paid', 'active'])
            ->get()
            ->each(function (Booking $booking): void {
                LockCode::query()->updateOrCreate(
                    ['booking_id' => $booking->id],
                    [
                        'code' => str_pad((string) (100000 + $booking->id), 6, '0', STR_PAD_LEFT),
                        'valid_from' => $booking->start_at,
                        'valid_until' => $booking->end_at,
                        'status' => $booking->status === 'active' ? 'active' : 'generated',
                    ],
                );
            });
    }
}
