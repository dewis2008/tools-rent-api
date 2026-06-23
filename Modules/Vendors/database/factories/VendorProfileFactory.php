<?php

namespace Modules\Vendors\Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Vendors\Models\VendorProfile;

class VendorProfileFactory extends Factory
{
    protected $model = VendorProfile::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory()->vendor(),
            'business_name' => fake()->company().' Rentals',
            'company_code' => fake()->optional()->numerify('########'),
            'vat_code' => fake()->optional()->numerify('LT#########'),
            'verification_status' => 'approved',
            'rating' => fake()->randomFloat(1, 3.8, 5),
        ];
    }
}
