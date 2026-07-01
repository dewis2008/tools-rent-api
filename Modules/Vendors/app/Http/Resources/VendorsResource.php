<?php

namespace Modules\Vendors\Http\Resources;

use App\Http\Resources\ApiResource;
use Illuminate\Http\Request;
use Modules\Users\Http\Resources\UsersResource;

class VendorsResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'business_name' => $this->business_name,
            'company_code' => $this->company_code,
            'vat_code' => $this->vat_code,
            'verification_status' => $this->verification_status,
            'rating' => $this->rating,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'user' => new UsersResource($this->whenLoaded('user')),
        ];
    }
}
