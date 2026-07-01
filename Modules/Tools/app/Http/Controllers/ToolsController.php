<?php

namespace Modules\Tools\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Modules\Tools\Http\Requests\IndexToolRequest;
use Modules\Tools\Http\Requests\StoreToolRequest;
use Modules\Tools\Http\Requests\UpdateToolRequest;
use Modules\Tools\Http\Resources\ToolsResource;
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

    public function index(IndexToolRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Tool::class);

        $user = $request->user();

        if ($user?->role === 'vendor') {
            $user->loadMissing('vendorProfile');
        }

        $query = Tool::query()
            ->visibleTo($user)
            ->with(['vendor', 'category', 'mainImage'])
            ->when(
                $user?->role === 'customer',
                fn (Builder $query) => $query->withExists([
                    'bookings as address_access' => fn (Builder $query) => $query
                        ->where('customer_id', $user->id)
                        ->whereIn('status', ['paid', 'active', 'completed']),
                ]),
            )
            ->when($request->filled('query'), function (Builder $query) use ($request): void {
                $search = '%'.trim((string) $request->validated('query')).'%';

                $query->where(function (Builder $query) use ($search): void {
                    $query
                        ->whereLike('title', $search)
                        ->orWhereLike('description', $search)
                        ->orWhereHas('category', fn (Builder $query) => $query->whereLike('name', $search))
                        ->orWhereHas('vendor', fn (Builder $query) => $query->whereLike('business_name', $search));
                });
            })
            ->when(
                $request->filled('category'),
                fn (Builder $query) => $query->where('category_id', $request->integer('category')),
            )
            ->when(
                $request->filled('city'),
                fn (Builder $query) => $query->whereLike('city', trim((string) $request->validated('city'))),
            )
            ->when(
                $request->filled('min_price'),
                fn (Builder $query) => $query->where('price_per_day', '>=', $request->float('min_price')),
            )
            ->when(
                $request->filled('max_price'),
                fn (Builder $query) => $query->where('price_per_day', '<=', $request->float('max_price')),
            )
            ->when(
                $request->filled('status'),
                fn (Builder $query) => $query->where('status', $request->validated('status')),
            )
            ->when(
                $request->filled('vendor_id'),
                fn (Builder $query) => $query->where('vendor_id', $request->integer('vendor_id')),
            )
            ->orderBy($request->sortColumn(), $request->sortDirection())
            ->orderBy('id', $request->sortDirection());

        return ToolsResource::collection(
            $query->paginate($request->pageSize())->withQueryString(),
        )->response();
    }

    public function store(StoreToolRequest $request): JsonResponse
    {
        $this->authorize('create', Tool::class);

        $tool = Tool::create($request->validated());

        return (new ToolsResource($tool->load(['vendor', 'category', 'mainImage'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Tool $tool): JsonResponse
    {
        $this->authorize('view', $tool);

        return (new ToolsResource($tool->load(['vendor', 'category', 'mainImage', 'images'])))->response();
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

        return (new ToolsResource($tool->load(['vendor', 'category', 'mainImage', 'images'])))->response();
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
