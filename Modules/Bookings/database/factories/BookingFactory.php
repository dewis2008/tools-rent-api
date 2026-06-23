<?php

namespace Modules\Bookings\Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Modules\Bookings\Models\Booking;
use Modules\Tools\Models\Tool;
use Modules\Vendors\Models\VendorProfile;

class BookingFactory extends Factory
{
    protected $model = Booking::class;

    public function definition(): array
    {
        $startAt = Carbon::instance(fake()->dateTimeBetween('+1 day', '+30 days'))->setTime(10, 0);
        $endAt = $startAt->copy()->addDays(fake()->numberBetween(1, 5));
        $rentalPrice = fake()->randomFloat(2, 20, 250);
        $depositAmount = fake()->randomFloat(2, 0, 100);
        $platformFee = round($rentalPrice * 0.1, 2);

        return [
            'tool_id' => Tool::factory(),
            'customer_id' => User::factory()->customer(),
            'vendor_id' => fn (array $attributes) => Tool::query()->find($attributes['tool_id'])?->vendor_id
                ?? VendorProfile::factory(),
            'start_at' => $startAt,
            'end_at' => $endAt,
            'status' => 'pending',
            'rental_price' => $rentalPrice,
            'deposit_amount' => $depositAmount,
            'platform_fee' => $platformFee,
            'vendor_amount' => $rentalPrice - $platformFee,
            'total_amount' => $rentalPrice + $depositAmount,
        ];
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'paid',
        ]);
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
        ]);
    }
}
