<?php

namespace Modules\LockCodes\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Bookings\Models\Booking;
use Modules\LockCodes\Models\LockCode;

class LockCodeFactory extends Factory
{
    protected $model = LockCode::class;

    public function definition(): array
    {
        $validFrom = now()->addDay();

        return [
            'booking_id' => Booking::factory()->paid(),
            'code' => fake()->numerify('######'),
            'valid_from' => $validFrom,
            'valid_until' => $validFrom->copy()->addDays(2),
            'status' => 'generated',
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
            'valid_from' => now()->subHour(),
            'valid_until' => now()->addDay(),
        ]);
    }
}
