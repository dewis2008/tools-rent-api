<?php

namespace Modules\Tools\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Bookings\Models\Booking;
use Modules\Categories\Models\Category;
use Modules\ToolImages\Models\ToolImage;
use Modules\Vendors\Models\VendorProfile;

class Tool extends Model
{
    protected $fillable = [
        'vendor_id',
        'category_id',
        'title',
        'description',
        'price_per_day',
        'deposit_amount',
        'city',
        'address',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'price_per_day' => 'decimal:2',
            'deposit_amount' => 'decimal:2',
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(VendorProfile::class, 'vendor_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ToolImage::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
}
