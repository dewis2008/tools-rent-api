<?php

namespace Modules\Vendors\Http\Requests;

use App\Http\Requests\FilteredListRequest;
use Modules\Vendors\Models\VendorProfile;

class IndexVendorRequest extends FilteredListRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', VendorProfile::class) ?? false;
    }

    protected function filterRules(): array
    {
        return [
            'query' => ['sometimes', 'nullable', 'string', 'max:200'],
            'verification_status' => ['sometimes', 'string', 'in:pending,approved,rejected'],
            'user_status' => ['sometimes', 'string', 'in:active,blocked,pending'],
            'min_rating' => ['sometimes', 'numeric', 'between:0,5'],
            'max_rating' => ['sometimes', 'numeric', 'between:0,5', 'gte:min_rating'],
        ];
    }

    protected function sortableColumns(): array
    {
        return [
            'created_at' => 'created_at',
            'business_name' => 'business_name',
            'rating' => 'rating',
            'verification_status' => 'verification_status',
        ];
    }
}
