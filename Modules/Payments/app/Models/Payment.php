<?php

namespace Modules\Payments\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Bookings\Models\Booking;
use Modules\Payments\Database\Factories\PaymentFactory;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_id',
        'customer_id',
        'provider',
        'provider_payment_id',
        'provider_refund_id',
        'refund_attempts',
        'status',
        'amount',
        'currency',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'refund_attempts' => 'integer',
            'paid_at' => 'datetime',
        ];
    }

    protected static function newFactory(): PaymentFactory
    {
        return PaymentFactory::new();
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }
}
