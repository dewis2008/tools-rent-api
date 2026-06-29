<?php

namespace Modules\Tools\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Modules\Tools\Http\Requests\StoreToolRequest;
use Modules\Tools\Http\Requests\UpdateToolRequest;
use Modules\Tools\Models\Tool;

class ToolsController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Tool::class);

        $query = Tool::query()->with(['vendor', 'category'])->latest();

        if (request()->user()->role === 'customer') {
            $query->where('status', 'active');
        }

        return response()->json($query->paginate());
    }

    public function store(StoreToolRequest $request): JsonResponse
    {
        $this->authorize('create', Tool::class);

        $tool = Tool::create($request->validated());

        return response()->json($tool->load(['vendor', 'category']), Response::HTTP_CREATED);
    }

    public function show(Tool $tool): JsonResponse
    {
        $this->authorize('view', $tool);

        return response()->json($tool->load(['vendor', 'category', 'images']));
    }

    public function update(UpdateToolRequest $request, Tool $tool): JsonResponse
    {
        $this->authorize('update', $tool);

        $tool->update($request->validated());

        return response()->json($tool->refresh()->load(['vendor', 'category', 'images']));
    }

    public function destroy(Tool $tool): Response
    {
        $this->authorize('delete', $tool);

        DB::transaction(function () use ($tool): void {
            $tool = Tool::query()->lockForUpdate()->findOrFail($tool->id);

            if ($tool->hasBookingHistory()) {
                throw new AuthorizationException(__('Tools with booking history cannot be deleted.'));
            }

            $tool->delete();
        });

        return response()->noContent();
    }
}
