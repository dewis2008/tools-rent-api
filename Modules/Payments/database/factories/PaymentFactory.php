<?php

namespace Modules\Payments\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Bookings\Models\Booking;
use Modules\Payments\Models\Payment;

class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'booking_id' => Booking::factory(),
            'customer_id' => fn (array $attributes) => Booking::query()->find($attributes['booking_id'])?->customer_id,
            'provider' => 'demo',
            'provider_payment_id' => null,
            'status' => 'pending',
            'amount' => fn (array $attributes) => Booking::query()->find($attributes['booking_id'])?->total_amount ?? 0,
            'currency' => 'EUR',
            'paid_at' => null,
        ];
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'paid',
            'provider_payment_id' => fake()->uuid(),
            'paid_at' => now(),
        ]);
    }
}
