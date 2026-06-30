<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Modules\Bookings\Models\Booking;
use Modules\Payments\Models\Payment;
use Modules\ToolImages\Services\ToolImageService;
use Modules\Vendors\Models\VendorProfile;

#[Fillable(['name', 'email', 'password', 'phone', 'role', 'status'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmailContract
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use Notifiable;

    protected static function booted(): void
    {
        static::deleting(function (User $user): void {
            if ($user->hasBookingHistory()) {
                throw new AuthorizationException(__('Users with booking history cannot be deleted.'));
            }

            app(ToolImageService::class)->deleteFilesForUser($user);
        });

        static::deleted(function (): void {
            app(ToolImageService::class)->processPendingDeletionsAfterCommit();
        });
    }

    public function vendorProfile(): HasOne
    {
        return $this->hasOne(VendorProfile::class);
    }

    public function customerBookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'customer_id');
    }

    public function vendorBookings(): HasManyThrough
    {
        return $this->hasManyThrough(
            Booking::class,
            VendorProfile::class,
            'user_id',
            'vendor_id',
        );
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'customer_id');
    }

    public function isEligibleVendor(): bool
    {
        return $this->role === 'vendor'
            && $this->status === 'active'
            && $this->hasVerifiedEmail();
    }

    public function hasBookingHistory(): bool
    {
        if ($this->customerBookings()->withTrashed()->exists()) {
            return true;
        }

        $vendorProfileIds = VendorProfile::withTrashed()
            ->where('user_id', $this->id)
            ->select('id');

        return Booking::withTrashed()
            ->whereIn('vendor_id', $vendorProfileIds)
            ->exists();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
