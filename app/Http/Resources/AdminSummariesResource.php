<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class AdminSummariesResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'users' => $this->resource['users'],
            'vendors' => $this->resource['vendors'],
            'categories' => $this->resource['categories'],
            'tools' => $this->resource['tools'],
            'bookings' => $this->resource['bookings'],
            'payments' => $this->resource['payments'],
            'pending_vendors' => $this->resource['pending_vendors'],
            'pending_tools' => $this->resource['pending_tools'],
            'active_bookings' => $this->resource['active_bookings'],
        ];
    }
}
