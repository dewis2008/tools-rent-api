<?php

namespace Modules\Bookings\Http\Requests;

use App\Http\Requests\FilteredListRequest;
use Modules\Bookings\Models\Booking;

class IndexBookingRequest extends FilteredListRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Booking::class) ?? false;
    }

    protected function filterRules(): array
    {
        return [
            'query' => ['sometimes', 'nullable', 'string', 'max:200'],
            'status' => ['sometimes', 'string', 'in:pending,paid,active,completed,cancelled'],
            'tool_id' => ['sometimes', 'integer', 'exists:tools,id'],
            'customer_id' => ['sometimes', 'integer', 'exists:users,id'],
            'vendor_id' => ['sometimes', 'integer', 'exists:vendor_profiles,id'],
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ];
    }

    protected function sortableColumns(): array
    {
        return [
            'created_at' => 'created_at',
            'start_at' => 'start_at',
            'end_at' => 'end_at',
            'total_amount' => 'total_amount',
            'status' => 'status',
        ];
    }
}
