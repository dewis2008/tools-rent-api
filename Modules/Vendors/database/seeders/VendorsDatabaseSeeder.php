<?php

namespace Modules\Vendors\Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Modules\Vendors\Models\VendorProfile;

class VendorsDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        collect([
            'vendor@tools-rent.test' => [
                'business_name' => 'Vilnius Tool Hub',
                'company_code' => '305000001',
                'vat_code' => 'LT100000001',
                'rating' => 4.8,
            ],
            'vendor.two@tools-rent.test' => [
                'business_name' => 'Kaunas Rental Works',
                'company_code' => '305000002',
                'vat_code' => 'LT100000002',
                'rating' => 4.6,
            ],
        ])->each(function (array $vendor, string $email): void {
            $user = User::query()->where('email', $email)->firstOrFail();

            VendorProfile::query()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    ...$vendor,
                    'verification_status' => 'approved',
                ],
            );
        });
    }
}
