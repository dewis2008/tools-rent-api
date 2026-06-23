<?php

namespace Modules\Tools\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Categories\Models\Category;
use Modules\Tools\Models\Tool;
use Modules\Vendors\Models\VendorProfile;

class ToolFactory extends Factory
{
    protected $model = Tool::class;

    public function definition(): array
    {
        return [
            'vendor_id' => VendorProfile::factory(),
            'category_id' => Category::factory(),
            'title' => Str::title(fake()->words(3, true)),
            'description' => fake()->sentence(12),
            'price_per_day' => fake()->randomFloat(2, 8, 95),
            'deposit_amount' => fake()->randomFloat(2, 0, 150),
            'city' => fake()->randomElement(['Vilnius', 'Kaunas', 'Klaipeda', 'Siauliai']),
            'address' => fake()->streetAddress(),
            'status' => 'active',
        ];
    }
}
