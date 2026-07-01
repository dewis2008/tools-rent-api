<?php

namespace Modules\Bookings\Http\Resources;

use App\Http\Resources\ApiResource;
use Illuminate\Http\Request;
use Modules\LockCodes\Http\Resources\LockCodesResource;
use Modules\Payments\Http\Resources\PaymentsResource;
use Modules\Tools\Http\Resources\ToolsResource;
use Modules\Users\Http\Resources\UsersResource;
use Modules\Vendors\Http\Resources\VendorsResource;

class BookingsResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tool_id' => $this->tool_id,
            'customer_id' => $this->customer_id,
            'vendor_id' => $this->vendor_id,
            'start_at' => $this->start_at,
            'end_at' => $this->end_at,
            'status' => $this->status,
            'expires_at' => $this->expires_at,
            'rental_price' => $this->rental_price,
            'deposit_amount' => $this->deposit_amount,
            'platform_fee' => $this->platform_fee,
            'vendor_amount' => $this->vendor_amount,
            'total_amount' => $this->total_amount,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'tool' => new ToolsResource($this->whenLoaded('tool')),
            'customer' => new UsersResource($this->whenLoaded('customer')),
            'vendor' => new VendorsResource($this->whenLoaded('vendor')),
            'payment' => new PaymentsResource($this->whenLoaded('payment')),
            'lock_code' => new LockCodesResource($this->whenLoaded('lockCode')),
        ];
    }
}
