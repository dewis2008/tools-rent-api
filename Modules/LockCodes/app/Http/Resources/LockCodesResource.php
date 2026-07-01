<?php

namespace Modules\LockCodes\Http\Resources;

use App\Http\Resources\ApiResource;
use Illuminate\Http\Request;
use Modules\Bookings\Http\Resources\BookingsResource;

class LockCodesResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'booking_id' => $this->booking_id,
            'valid_from' => $this->valid_from,
            'valid_until' => $this->valid_until,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'booking' => new BookingsResource($this->whenLoaded('booking')),
        ];
    }
}
