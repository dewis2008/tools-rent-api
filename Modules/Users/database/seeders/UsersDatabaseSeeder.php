<?php

namespace Modules\Users\Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class UsersDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing']) || ! config('app.allow_demo_seeding')) {
            throw new RuntimeException(
                'Demo users can only be seeded in local or testing environments when ALLOW_DEMO_SEEDING is enabled.',
            );
        }

        collect([
            [
                'name' => 'Demo Admin',
                'email' => 'admin@tools-rent.test',
                'phone' => '+37060000001',
                'role' => 'admin',
            ],
            [
                'name' => 'Demo Vendor',
                'email' => 'vendor@tools-rent.test',
                'phone' => '+37060000002',
                'role' => 'vendor',
            ],
            [
                'name' => 'Demo Vendor Two',
                'email' => 'vendor.two@tools-rent.test',
                'phone' => '+37060000003',
                'role' => 'vendor',
            ],
            [
                'name' => 'Demo Customer',
                'email' => 'customer@tools-rent.test',
                'phone' => '+37060000004',
                'role' => 'customer',
            ],
            [
                'name' => 'Demo Customer Two',
                'email' => 'customer.two@tools-rent.test',
                'phone' => '+37060000005',
                'role' => 'customer',
            ],
        ])->each(function (array $user): void {
            User::query()->updateOrCreate(
                ['email' => $user['email']],
                [
                    ...$user,
                    'password' => Hash::make('password'),
                    'status' => 'active',
                    'email_verified_at' => now(),
                ],
            );
        });
    }
}
