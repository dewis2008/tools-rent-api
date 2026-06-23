<?php

namespace Modules\Categories\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Categories\Models\Category;

class CategoriesDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        collect([
            ['name' => 'Power Tools', 'slug' => 'power-tools'],
            ['name' => 'Garden Equipment', 'slug' => 'garden-equipment'],
            ['name' => 'Lifting Gear', 'slug' => 'lifting-gear'],
            ['name' => 'Cleaning Machines', 'slug' => 'cleaning-machines'],
        ])->each(fn (array $category) => Category::query()->updateOrCreate(
            ['slug' => $category['slug']],
            $category,
        ));
    }
}
