<?php

namespace Modules\Vendors\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Bookings\Models\Booking;
use Modules\ToolImages\Services\ToolImageService;
use Modules\Tools\Models\Tool;

class VendorProfile extends Model
{
    protected $fillable = [
        'user_id',
        'business_name',
        'company_code',
        'vat_code',
        'verification_status',
        'rating',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'decimal:1',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (VendorProfile $vendorProfile): void {
            app(ToolImageService::class)->deleteFilesForVendorProfile($vendorProfile);
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tools(): HasMany
    {
        return $this->hasMany(Tool::class, 'vendor_id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'vendor_id');
    }
}
