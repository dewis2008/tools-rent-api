<?php

namespace Modules\Payments\Http\Resources;

use App\Http\Resources\ApiResource;
use Illuminate\Http\Request;
use Modules\Bookings\Http\Resources\BookingsResource;
use Modules\Users\Http\Resources\UsersResource;

class PaymentsResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'booking_id' => $this->booking_id,
            'customer_id' => $this->customer_id,
            'provider' => $this->provider,
            'provider_payment_id' => $this->provider_payment_id,
            'provider_refund_id' => $this->provider_refund_id,
            'refund_attempts' => $this->refund_attempts,
            'status' => $this->status,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'paid_at' => $this->paid_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'booking' => new BookingsResource($this->whenLoaded('booking')),
            'customer' => new UsersResource($this->whenLoaded('customer')),
        ];
    }
}
