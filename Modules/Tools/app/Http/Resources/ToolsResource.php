<?php

namespace Modules\Tools\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ToolImages\Http\Resources\ToolImagesResource;

class ToolsResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'main_image' => new ToolImagesResource($this->whenLoaded('mainImage')),
            'images' => ToolImagesResource::collection($this->whenLoaded('images')),
            'address' => $this->when(
                $this->canRevealAddressTo($request->user()),
                $this->address,
            ),
        ];
    }

    private function canRevealAddressTo(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->role === 'admin') {
            return true;
        }

        if ($user->role === 'vendor') {
            return $user->vendorProfile?->id === $this->resource->vendor_id;
        }

        if ($user->role !== 'customer') {
            return false;
        }

        if (array_key_exists('address_access', $this->resource->getAttributes())) {
            return (bool) $this->resource->getAttribute('address_access');
        }

        return $this->bookings()
            ->where('customer_id', $user->id)
            ->whereIn('status', ['paid', 'active', 'completed'])
            ->exists();
    }
}
