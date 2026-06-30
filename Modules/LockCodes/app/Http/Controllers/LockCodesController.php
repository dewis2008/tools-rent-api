<?php

namespace Modules\LockCodes\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Modules\LockCodes\Http\Requests\StoreLockCodeRequest;
use Modules\LockCodes\Http\Requests\UpdateLockCodeRequest;
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

        return response()->json($query->paginate());
    }

    public function store(StoreLockCodeRequest $request): JsonResponse
    {
        $this->authorize('create', LockCode::class);

        $lockCode = LockCode::create($request->validated());

        return response()->json($lockCode->load('booking'), Response::HTTP_CREATED);
    }

    public function show(LockCode $lockCode): JsonResponse
    {
        $this->authorize('view', $lockCode);

        return response()->json($lockCode->load('booking'));
    }

    public function reveal(LockCode $lockCode): JsonResponse
    {
        $this->authorize('reveal', $lockCode);

        $user = request()->user();

        Log::info('Lock code revealed.', [
            'lock_code_id' => $lockCode->id,
            'booking_id' => $lockCode->booking_id,
            'user_id' => $user?->id,
            'user_role' => $user?->role,
        ]);

        return response()->json([
            'code' => $lockCode->code,
        ]);
    }

    public function update(
        UpdateLockCodeRequest $request,
        LockCode $lockCode,
        LockCodeService $lockCodes,
    ): JsonResponse {
        $this->authorize('update', $lockCode);

        $lockCode = $lockCodes->update($lockCode, $request->validated(), $request->user());

        return response()->json($lockCode->load('booking'));
    }

    public function destroy(LockCode $lockCode, LockCodeService $lockCodes): Response
    {
        $this->authorize('delete', $lockCode);

        $lockCodes->revoke($lockCode, request()->user());

        return response()->noContent();
    }
}
