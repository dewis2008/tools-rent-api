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
    private const ReviewableFields = [
        'category_id',
        'title',
        'description',
        'price_per_day',
        'deposit_amount',
        'city',
        'address',
    ];

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Tool::class);

        $query = Tool::query()
            ->visibleTo(request()->user())
            ->with(['vendor', 'category'])
            ->latest();

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

        $validated = $request->validated();
        $user = $request->user();
        $tool = DB::transaction(function () use ($tool, $validated, $user): Tool {
            $tool = Tool::query()->lockForUpdate()->findOrFail($tool->id);

            if (! $user->can('update', $tool)) {
                throw new AuthorizationException;
            }

            $tool->fill($validated);

            if ($user->role !== 'admin'
                && $tool->getOriginal('status') === 'active'
                && $tool->isDirty(self::ReviewableFields)) {
                $tool->status = 'pending';
            }

            $tool->save();

            return $tool;
        });

        return response()->json($tool->load(['vendor', 'category', 'images']));
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
