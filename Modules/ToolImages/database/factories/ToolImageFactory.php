<?php

namespace Modules\ToolImages\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\ToolImages\Models\ToolImage;
use Modules\Tools\Models\Tool;

class ToolImageFactory extends Factory
{
    protected $model = ToolImage::class;

    public function definition(): array
    {
        return [
            'tool_id' => Tool::factory(),
            'image_path' => fn (array $attributes) => "demo/tool-images/{$attributes['tool_id']}/".fake()->slug().'.jpg',
            'is_main' => false,
            'sort_order' => fake()->numberBetween(0, 5),
        ];
    }

    public function main(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_main' => true,
            'sort_order' => 0,
        ]);
    }
}
