<?php

namespace Modules\Tools\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Categories\Models\Category;
use Modules\Tools\Models\Tool;
use Modules\Vendors\Models\VendorProfile;

class ToolsDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $vendors = VendorProfile::query()->pluck('id', 'business_name');
        $categories = Category::query()->pluck('id', 'slug');

        collect([
            [
                'vendor_id' => $vendors['Vilnius Tool Hub'],
                'category_id' => $categories['power-tools'],
                'title' => 'Cordless Hammer Drill',
                'description' => '18V drill kit with two batteries, charger, and masonry bit set.',
                'price_per_day' => 18,
                'deposit_amount' => 40,
                'city' => 'Vilnius',
                'address' => 'Gedimino pr. 12',
                'status' => 'active',
            ],
            [
                'vendor_id' => $vendors['Vilnius Tool Hub'],
                'category_id' => $categories['cleaning-machines'],
                'title' => 'Industrial Carpet Cleaner',
                'description' => 'Extractor for upholstery, carpets, and car interiors.',
                'price_per_day' => 32,
                'deposit_amount' => 80,
                'city' => 'Vilnius',
                'address' => 'Kalvariju g. 88',
                'status' => 'active',
            ],
            [
                'vendor_id' => $vendors['Vilnius Tool Hub'],
                'category_id' => $categories['garden-equipment'],
                'title' => 'Electric Lawn Scarifier',
                'description' => 'Compact scarifier with collection bag for small and medium lawns.',
                'price_per_day' => 24,
                'deposit_amount' => 60,
                'city' => 'Vilnius',
                'address' => 'Ukmerges g. 221',
                'status' => 'inactive',
            ],
            [
                'vendor_id' => $vendors['Kaunas Rental Works'],
                'category_id' => $categories['lifting-gear'],
                'title' => 'Material Lift 150kg',
                'description' => 'Foldable lift for drywall, ventilation, and light installation jobs.',
                'price_per_day' => 45,
                'deposit_amount' => 120,
                'city' => 'Kaunas',
                'address' => 'Savanoriu pr. 201',
                'status' => 'active',
            ],
            [
                'vendor_id' => $vendors['Kaunas Rental Works'],
                'category_id' => $categories['power-tools'],
                'title' => 'Rotary Laser Level',
                'description' => 'Self-leveling rotary laser with tripod and receiver.',
                'price_per_day' => 28,
                'deposit_amount' => 90,
                'city' => 'Kaunas',
                'address' => 'Taikos pr. 43',
                'status' => 'active',
            ],
        ])->each(fn (array $tool) => Tool::query()->updateOrCreate(
            [
                'vendor_id' => $tool['vendor_id'],
                'title' => $tool['title'],
            ],
            $tool,
        ));
    }
}
