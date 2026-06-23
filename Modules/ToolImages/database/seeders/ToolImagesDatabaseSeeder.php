<?php

namespace Modules\ToolImages\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Modules\ToolImages\Models\ToolImage;
use Modules\Tools\Models\Tool;
use Modules\Vendors\Models\VendorProfile;

class ToolImagesDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $vendorIds = VendorProfile::query()
            ->whereIn('business_name', ['Vilnius Tool Hub', 'Kaunas Rental Works'])
            ->pluck('id');

        Tool::query()
            ->whereIn('vendor_id', $vendorIds)
            ->whereIn('title', [
                'Cordless Hammer Drill',
                'Industrial Carpet Cleaner',
                'Electric Lawn Scarifier',
                'Material Lift 150kg',
                'Rotary Laser Level',
            ])
            ->orderBy('id')
            ->get()
            ->each(function (Tool $tool): void {
                $tool->images()->update(['is_main' => false]);

                collect([0, 1])->each(function (int $index) use ($tool): void {
                    ToolImage::query()->updateOrCreate(
                        [
                            'tool_id' => $tool->id,
                            'sort_order' => $index,
                        ],
                        [
                            'image_path' => "demo/tool-images/{$tool->id}/".Str::slug($tool->title)."-{$index}.jpg",
                            'is_main' => $index === 0,
                        ],
                    );
                });
            });
    }
}
