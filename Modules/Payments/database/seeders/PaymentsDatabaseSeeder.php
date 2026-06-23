<?php

namespace Modules\Payments\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Modules\Bookings\Models\Booking;
use Modules\Payments\Models\Payment;

class PaymentsDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        Booking::query()
            ->whereIn('status', ['pending', 'paid', 'active', 'completed'])
            ->get()
            ->each(function (Booking $booking): void {
                $isPending = $booking->status === 'pending';

                Payment::query()->updateOrCreate(
                    ['booking_id' => $booking->id],
                    [
                        'customer_id' => $booking->customer_id,
                        'provider' => 'demo',
                        'provider_payment_id' => $isPending ? null : 'demo-'.Str::lower(Str::random(10)),
                        'status' => $isPending ? 'pending' : 'paid',
                        'amount' => $booking->total_amount,
                        'currency' => 'EUR',
                        'paid_at' => $isPending ? null : now()->subDay(),
                    ],
                );
            });
    }
}
