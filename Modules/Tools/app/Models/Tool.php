<?php

namespace Modules\Tools\Models;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Bookings\Models\Booking;
use Modules\Categories\Models\Category;
use Modules\ToolImages\Models\ToolImage;
use Modules\ToolImages\Services\ToolImageService;
use Modules\Tools\Database\Factories\ToolFactory;
use Modules\Vendors\Models\VendorProfile;

class Tool extends Model
{
    public const MaxPricePerDay = 100_000;

    public const MaxDepositAmount = 1_000_000;

    use HasFactory;
    use SoftDeletes;

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

    protected $hidden = [
        'address',
        'address_access',
    ];

    protected function casts(): array
    {
        return [
            'price_per_day' => 'decimal:2',
            'deposit_amount' => 'decimal:2',
        ];
    }

    protected static function newFactory(): ToolFactory
    {
        return ToolFactory::new();
    }

    protected static function booted(): void
    {
        static::deleting(function (Tool $tool): void {
            if ($tool->hasBookingHistory()) {
                throw new AuthorizationException(__('Tools with booking history cannot be deleted.'));
            }

            app(ToolImageService::class)->deleteFilesForTool($tool);

            if (! $tool->isForceDeleting()) {
                $tool->images()->delete();
            }
        });

        static::deleted(function (): void {
            app(ToolImageService::class)->processPendingDeletionsAfterCommit();
        });
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

    public function hasBookingHistory(): bool
    {
        return $this->bookings()->withTrashed()->exists();
    }

    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if (! $user) {
            return $query->publiclyAvailable();
        }

        if ($user->role === 'admin') {
            return $query;
        }

        if ($user->role !== 'vendor') {
            return $query->publiclyAvailable();
        }

        return $query->where(function (Builder $query) use ($user): void {
            $query
                ->publiclyAvailable()
                ->orWhereIn(
                    'vendor_id',
                    $user->vendorProfile()
                        ->where('verification_status', 'approved')
                        ->select('id'),
                );
        });
    }

    public function scopePubliclyAvailable(Builder $query): Builder
    {
        return $query
            ->where('status', 'active')
            ->whereHas('vendor', fn (Builder $query) => $query->eligibleForRentals());
    }

    public function isPubliclyAvailable(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        return $this->vendor()->eligibleForRentals()->exists();
    }
}
