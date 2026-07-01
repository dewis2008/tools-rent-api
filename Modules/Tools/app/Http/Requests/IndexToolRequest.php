<?php

namespace Modules\Tools\Http\Requests;

use App\Http\Requests\FilteredListRequest;
use Modules\Tools\Models\Tool;

class IndexToolRequest extends FilteredListRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function filterRules(): array
    {
        return [
            'query' => ['sometimes', 'nullable', 'string', 'max:200'],
            'category' => ['sometimes', 'integer', 'exists:categories,id'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'min_price' => ['sometimes', 'numeric', 'min:0', 'max:'.Tool::MaxPricePerDay],
            'max_price' => ['sometimes', 'numeric', 'min:0', 'max:'.Tool::MaxPricePerDay, 'gte:min_price'],
            'status' => ['sometimes', 'string', 'in:pending,active,inactive,rejected'],
            'vendor_id' => ['sometimes', 'integer', 'exists:vendor_profiles,id'],
        ];
    }

    protected function sortableColumns(): array
    {
        return [
            'created_at' => 'created_at',
            'title' => 'title',
            'price' => 'price_per_day',
            'city' => 'city',
            'status' => 'status',
        ];
    }
}
