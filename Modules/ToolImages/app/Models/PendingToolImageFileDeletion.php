<?php

namespace Modules\ToolImages\Models;

use Illuminate\Database\Eloquent\Model;

class PendingToolImageFileDeletion extends Model
{
    protected $fillable = [
        'image_path',
        'attempts',
        'last_attempted_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'last_attempted_at' => 'datetime',
        ];
    }
}
