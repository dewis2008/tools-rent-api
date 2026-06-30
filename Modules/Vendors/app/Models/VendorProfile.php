<?php

namespace Modules\Vendors\Models;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Bookings\Models\Booking;
use Modules\ToolImages\Services\ToolImageService;
use Modules\Tools\Models\Tool;
use Modules\Vendors\Database\Factories\VendorProfileFactory;

class VendorProfile extends Model
{
    use HasFactory;
    use SoftDeletes;

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

    protected static function newFactory(): VendorProfileFactory
    {
        return VendorProfileFactory::new();
    }

    protected static function booted(): void
    {
        static::deleting(function (VendorProfile $vendorProfile): void {
            if ($vendorProfile->hasBookingHistory()) {
                throw new AuthorizationException(__('Vendors with booking history cannot be deleted.'));
            }

            if ($vendorProfile->isForceDeleting()) {
                app(ToolImageService::class)->deleteFilesForVendorProfile($vendorProfile);

                return;
            }

            $vendorProfile->tools->each->delete();
        });

        static::deleted(function (): void {
            app(ToolImageService::class)->processPendingDeletionsAfterCommit();
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

    public function hasBookingHistory(): bool
    {
        return $this->bookings()->withTrashed()->exists();
    }
}
