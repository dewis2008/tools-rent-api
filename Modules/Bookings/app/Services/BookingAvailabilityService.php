<?php

namespace Modules\Bookings\Services;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Modules\Bookings\Models\Booking;
use Modules\Tools\Models\Tool;

class BookingAvailabilityService
{
    private const BlockingStatuses = ['paid', 'active'];

    public function isAvailable(Tool $tool, CarbonInterface $startAt, CarbonInterface $endAt): bool
    {
        if (! $tool->isPubliclyAvailable()) {
            return false;
        }

        return ! $this->hasConflict($tool->id, $startAt, $endAt);
    }

    public function hasConflict(
        int $toolId,
        CarbonInterface $startAt,
        CarbonInterface $endAt,
        bool $lockForUpdate = false,
    ): bool {
        $query = Booking::query()
            ->where('tool_id', $toolId)
            ->where(function (Builder $query): void {
                $query
                    ->whereIn('status', self::BlockingStatuses)
                    ->orWhere(function (Builder $query): void {
                        $query
                            ->where('status', 'pending')
                            ->where(function (Builder $query): void {
                                $query
                                    ->whereNull('expires_at')
                                    ->orWhere('expires_at', '>', now());
                            });
                    });
            })
            ->where('start_at', '<', $endAt)
            ->where('end_at', '>', $startAt);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->exists();
    }
}
