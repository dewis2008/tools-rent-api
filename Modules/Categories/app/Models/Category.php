<?php

namespace Modules\Categories\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Tools\Models\Tool;

class Category extends Model
{
    protected $fillable = [
        'name',
        'slug',
    ];

    public function tools(): HasMany
    {
        return $this->hasMany(Tool::class);
    }
}
