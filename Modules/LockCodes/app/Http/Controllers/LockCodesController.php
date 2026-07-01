<?php

namespace Modules\LockCodes\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\LockCodes\Http\Requests\StoreLockCodeRequest;
use Modules\LockCodes\Http\Requests\UpdateLockCodeRequest;
use Modules\LockCodes\Http\Resources\LockCodesResource;
use Modules\LockCodes\Models\LockCode;
use Modules\LockCodes\Services\LockCodeService;

class LockCodesController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', LockCode::class);

        $query = LockCode::query()->with('booking')->latest();
        $user = request()->user();

        if ($user->role === 'vendor') {
            $query->whereHas('booking', fn ($query) => $query->where('vendor_id', $user->vendorProfile?->id ?? 0));
        }

        if ($user->role === 'customer') {
            $query->whereHas('booking', fn ($query) => $query->where('customer_id', $user->id));
        }

        return LockCodesResource::collection($query->paginate())->response();
    }

    public function store(StoreLockCodeRequest $request, LockCodeService $lockCodes): JsonResponse
    {
        $this->authorize('create', LockCode::class);

        $lockCode = $lockCodes->create($request->validated(), $request->user());

        return (new LockCodesResource($lockCode->load('booking')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(LockCode $lockCode): JsonResponse
    {
        $this->authorize('view', $lockCode);

        return (new LockCodesResource($lockCode->load('booking')))->response();
    }

    public function update(
        UpdateLockCodeRequest $request,
        LockCode $lockCode,
        LockCodeService $lockCodes,
    ): JsonResponse {
        $this->authorize('update', $lockCode);

        $lockCode = $lockCodes->update($lockCode, $request->validated(), $request->user());

        return (new LockCodesResource($lockCode->load('booking')))->response();
    }

    public function destroy(LockCode $lockCode, LockCodeService $lockCodes): Response
    {
        $this->authorize('delete', $lockCode);

        $lockCodes->revoke($lockCode, request()->user());

        return response()->noContent();
    }
}
