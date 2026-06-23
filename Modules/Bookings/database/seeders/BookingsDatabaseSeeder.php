<?php

namespace Modules\Bookings\Database\Seeders;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Modules\Bookings\Models\Booking;
use Modules\Tools\Models\Tool;

class BookingsDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $customers = User::query()
            ->whereIn('email', ['customer@tools-rent.test', 'customer.two@tools-rent.test'])
            ->get()
            ->keyBy('email');
        $tools = Tool::query()
            ->whereIn('title', [
                'Cordless Hammer Drill',
                'Industrial Carpet Cleaner',
                'Material Lift 150kg',
                'Rotary Laser Level',
            ])
            ->get()
            ->keyBy('title');

        $this->seedBookings($customers, $tools);
    }

    private function seedBookings(Collection $customers, Collection $tools): void
    {
        collect([
            [
                'tool' => 'Cordless Hammer Drill',
                'customer' => 'customer@tools-rent.test',
                'status' => 'pending',
                'start_at' => now()->addDays(2)->setTime(9, 0),
                'end_at' => now()->addDays(4)->setTime(18, 0),
            ],
            [
                'tool' => 'Industrial Carpet Cleaner',
                'customer' => 'customer@tools-rent.test',
                'status' => 'paid',
                'start_at' => now()->addDays(5)->setTime(10, 0),
                'end_at' => now()->addDays(6)->setTime(18, 0),
            ],
            [
                'tool' => 'Material Lift 150kg',
                'customer' => 'customer.two@tools-rent.test',
                'status' => 'active',
                'start_at' => now()->subDay()->setTime(8, 0),
                'end_at' => now()->addDay()->setTime(17, 0),
            ],
            [
                'tool' => 'Rotary Laser Level',
                'customer' => 'customer.two@tools-rent.test',
                'status' => 'completed',
                'start_at' => now()->subDays(8)->setTime(8, 0),
                'end_at' => now()->subDays(6)->setTime(17, 0),
            ],
        ])->each(function (array $booking) use ($customers, $tools): void {
            $tool = $tools[$booking['tool']];
            $customer = $customers[$booking['customer']];
            $amounts = $this->amounts($tool, $booking['start_at'], $booking['end_at']);

            Booking::query()->updateOrCreate(
                [
                    'tool_id' => $tool->id,
                    'customer_id' => $customer->id,
                    'status' => $booking['status'],
                ],
                [
                    'vendor_id' => $tool->vendor_id,
                    'start_at' => $booking['start_at'],
                    'end_at' => $booking['end_at'],
                    ...$amounts,
                ],
            );
        });
    }

    private function amounts(Tool $tool, Carbon $startAt, Carbon $endAt): array
    {
        $days = max(1, (int) $startAt->diffInDays($endAt));
        $rentalPrice = (float) $tool->price_per_day * $days;
        $depositAmount = (float) $tool->deposit_amount;
        $platformFee = round($rentalPrice * 0.1, 2);

        return [
            'rental_price' => $rentalPrice,
            'deposit_amount' => $depositAmount,
            'platform_fee' => $platformFee,
            'vendor_amount' => $rentalPrice - $platformFee,
            'total_amount' => $rentalPrice + $depositAmount,
        ];
    }
}
