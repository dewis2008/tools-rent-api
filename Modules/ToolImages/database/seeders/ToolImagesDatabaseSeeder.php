<?php

namespace Modules\ToolImages\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Modules\ToolImages\Models\ToolImage;
use Modules\Tools\Models\Tool;

class ToolImagesDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        Tool::query()
            ->orderBy('id')
            ->get()
            ->each(function (Tool $tool): void {
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
