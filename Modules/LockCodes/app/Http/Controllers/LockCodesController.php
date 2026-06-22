<?php

namespace Modules\LockCodes\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\LockCodes\Http\Requests\StoreLockCodeRequest;
use Modules\LockCodes\Http\Requests\UpdateLockCodeRequest;
use Modules\LockCodes\Models\LockCode;

class LockCodesController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(LockCode::query()->with('booking')->latest()->paginate());
    }

    public function store(StoreLockCodeRequest $request): JsonResponse
    {
        $lockCode = LockCode::create($request->validated());

        return response()->json($lockCode->load('booking'), Response::HTTP_CREATED);
    }

    public function show(LockCode $lockCode): JsonResponse
    {
        return response()->json($lockCode->load('booking'));
    }

    public function update(UpdateLockCodeRequest $request, LockCode $lockCode): JsonResponse
    {
        $lockCode->update($request->validated());

        return response()->json($lockCode->refresh()->load('booking'));
    }

    public function destroy(LockCode $lockCode): Response
    {
        $lockCode->delete();

        return response()->noContent();
    }
}
