<?php

namespace Modules\Tools\Http\Resources;

use App\Http\Resources\ApiResource;
use App\Models\User;
use Illuminate\Http\Request;
use Modules\Categories\Http\Resources\CategoriesResource;
use Modules\ToolImages\Http\Resources\ToolImagesResource;
use Modules\Vendors\Http\Resources\VendorsResource;

class ToolsResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'vendor_id' => $this->vendor_id,
            'category_id' => $this->category_id,
            'title' => $this->title,
            'description' => $this->description,
            'price_per_day' => $this->price_per_day,
            'deposit_amount' => $this->deposit_amount,
            'city' => $this->city,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'vendor' => new VendorsResource($this->whenLoaded('vendor')),
            'category' => new CategoriesResource($this->whenLoaded('category')),
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
