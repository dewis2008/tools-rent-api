<?php

namespace Modules\Payments\Http\Requests;

use App\Http\Requests\FilteredListRequest;
use Modules\Bookings\Models\Booking;
use Modules\Payments\Models\Payment;

class IndexPaymentRequest extends FilteredListRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Payment::class) ?? false;
    }

    protected function filterRules(): array
    {
        return [
            'query' => ['sometimes', 'nullable', 'string', 'max:200'],
            'status' => ['sometimes', 'string', 'in:pending,paid,failed,refund_pending,refund_failed,refunded'],
            'provider' => ['sometimes', 'string', 'in:demo,stripe,paysera,manual'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'booking_id' => ['sometimes', 'integer', 'exists:bookings,id'],
            'customer_id' => ['sometimes', 'integer', 'exists:users,id'],
            'vendor_id' => ['sometimes', 'integer', 'exists:vendor_profiles,id'],
            'min_amount' => ['sometimes', 'numeric', 'min:0', 'max:'.Booking::MaxMoneyAmount],
            'max_amount' => ['sometimes', 'numeric', 'min:0', 'max:'.Booking::MaxMoneyAmount, 'gte:min_amount'],
        ];
    }

    protected function sortableColumns(): array
    {
        return [
            'created_at' => 'created_at',
            'paid_at' => 'paid_at',
            'amount' => 'amount',
            'status' => 'status',
            'provider' => 'provider',
        ];
    }
}
