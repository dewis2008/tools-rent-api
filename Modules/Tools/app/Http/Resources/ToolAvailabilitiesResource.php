<?php

namespace Modules\Tools\Http\Resources;

use App\Http\Resources\ApiResource;
use Illuminate\Http\Request;

class ToolAvailabilitiesResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'tool_id' => $this->resource['tool_id'],
            'start_at' => $this->resource['start_at'],
            'end_at' => $this->resource['end_at'],
            'available' => $this->resource['available'],
        ];
    }
}
