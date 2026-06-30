<?php

namespace Modules\Bookings\Models;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Bookings\Database\Factories\BookingFactory;
use Modules\LockCodes\Models\LockCode;
use Modules\Payments\Models\Payment;
use Modules\Tools\Models\Tool;
use Modules\Vendors\Models\VendorProfile;

class Booking extends Model
{
    public const MaxRentalDays = 365;

    public const MaxMoneyAmount = 99_999_999.99;

    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'tool_id',
        'customer_id',
        'vendor_id',
        'start_at',
        'end_at',
        'status',
        'expires_at',
        'rental_price',
        'deposit_amount',
        'platform_fee',
        'vendor_amount',
        'total_amount',
    ];

    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'expires_at' => 'datetime',
            'rental_price' => 'decimal:2',
            'deposit_amount' => 'decimal:2',
            'platform_fee' => 'decimal:2',
            'vendor_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
        ];
    }

    protected static function newFactory(): BookingFactory
    {
        return BookingFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (Booking $booking): void {
            if (($booking->status ?? 'pending') !== 'pending' || $booking->expires_at) {
                return;
            }

            $booking->expires_at = now()->addMinutes(
                (int) config('bookings.pending_expiration_minutes'),
            );
        });
    }

    public function tool(): BelongsTo
    {
        return $this->belongsTo(Tool::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(VendorProfile::class, 'vendor_id');
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    public function lockCode(): HasOne
    {
        return $this->hasOne(LockCode::class);
    }

    public function isSafelyDeletable(): bool
    {
        if ($this->status !== 'pending') {
            return false;
        }

        if ($this->payment()->exists()) {
            return false;
        }

        return ! $this->lockCode()->exists();
    }

    public function isRentalActiveAt(CarbonInterface $dateTime): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        return $this->isWithinRentalWindow($dateTime);
    }

    public function isWithinRentalWindow(CarbonInterface $dateTime): bool
    {
        return $this->start_at->lte($dateTime)
            && $this->end_at->gt($dateTime);
    }
}
