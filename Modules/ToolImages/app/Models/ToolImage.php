<?php

namespace Modules\ToolImages\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\ToolImages\Database\Factories\ToolImageFactory;
use Modules\Tools\Models\Tool;

class ToolImage extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'tool_id',
        'image_path',
        'is_main',
        'sort_order',
    ];

    protected $hidden = [
        'main_tool_id',
    ];

    protected function casts(): array
    {
        return [
            'is_main' => 'boolean',
        ];
    }

    protected static function newFactory(): ToolImageFactory
    {
        return ToolImageFactory::new();
    }

    public function tool(): BelongsTo
    {
        return $this->belongsTo(Tool::class);
    }
}
