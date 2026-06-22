<?php

namespace Modules\ToolImages\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Tools\Models\Tool;

class ToolImage extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'tool_id',
        'image_path',
        'is_main',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_main' => 'boolean',
        ];
    }

    public function tool(): BelongsTo
    {
        return $this->belongsTo(Tool::class);
    }
}
