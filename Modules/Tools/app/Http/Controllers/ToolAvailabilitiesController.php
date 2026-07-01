<?php

namespace Modules\Tools\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Modules\Bookings\Services\BookingAvailabilityService;
use Modules\Tools\Http\Requests\ToolAvailabilityRequest;
use Modules\Tools\Http\Resources\ToolAvailabilitiesResource;
use Modules\Tools\Models\Tool;

class ToolAvailabilitiesController extends Controller
{
    public function show(
        ToolAvailabilityRequest $request,
        Tool $tool,
        BookingAvailabilityService $availability,
    ): JsonResponse {
        $this->authorize('view', $tool);

        $startAt = Carbon::parse($request->validated('start_at'));
        $endAt = Carbon::parse($request->validated('end_at'));

        return (new ToolAvailabilitiesResource([
            'tool_id' => $tool->id,
            'start_at' => $startAt->toISOString(),
            'end_at' => $endAt->toISOString(),
            'available' => $availability->isAvailable($tool, $startAt, $endAt),
        ]))->response();
    }
}
