<?php

namespace Modules\Categories\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Categories\Database\Factories\CategoryFactory;
use Modules\Tools\Models\Tool;

class Category extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
    ];

    protected static function newFactory(): CategoryFactory
    {
        return CategoryFactory::new();
    }

    public function tools(): HasMany
    {
        return $this->hasMany(Tool::class);
    }
}
