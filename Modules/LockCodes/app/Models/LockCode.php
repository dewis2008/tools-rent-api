<?php

namespace Modules\LockCodes\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Bookings\Models\Booking;
use Modules\LockCodes\Database\Factories\LockCodeFactory;

class LockCode extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_id',
        'code',
        'valid_from',
        'valid_until',
        'status',
    ];

    protected $hidden = [
        'code',
    ];

    protected function casts(): array
    {
        return [
            'code' => 'encrypted',
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
        ];
    }

    protected static function newFactory(): LockCodeFactory
    {
        return LockCodeFactory::new();
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
