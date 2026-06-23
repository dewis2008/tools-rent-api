<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Modules\Bookings\Database\Seeders\BookingsDatabaseSeeder;
use Modules\Categories\Database\Seeders\CategoriesDatabaseSeeder;
use Modules\LockCodes\Database\Seeders\LockCodesDatabaseSeeder;
use Modules\Payments\Database\Seeders\PaymentsDatabaseSeeder;
use Modules\ToolImages\Database\Seeders\ToolImagesDatabaseSeeder;
use Modules\Tools\Database\Seeders\ToolsDatabaseSeeder;
use Modules\Users\Database\Seeders\UsersDatabaseSeeder;
use Modules\Vendors\Database\Seeders\VendorsDatabaseSeeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            UsersDatabaseSeeder::class,
            CategoriesDatabaseSeeder::class,
            VendorsDatabaseSeeder::class,
            ToolsDatabaseSeeder::class,
            ToolImagesDatabaseSeeder::class,
            BookingsDatabaseSeeder::class,
            PaymentsDatabaseSeeder::class,
            LockCodesDatabaseSeeder::class,
        ]);
    }
}
