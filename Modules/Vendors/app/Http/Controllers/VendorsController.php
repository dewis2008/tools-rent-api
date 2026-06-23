<?php

namespace Modules\Vendors\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\Vendors\Http\Requests\StoreVendorRequest;
use Modules\Vendors\Http\Requests\UpdateVendorRequest;
use Modules\Vendors\Models\VendorProfile;

class VendorsController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', VendorProfile::class);

        $query = VendorProfile::query()->latest();
        $user = request()->user();

        if ($user->role === 'vendor') {
            $query->where('user_id', $user->id);
        }

        return response()->json($query->paginate());
    }

    public function store(StoreVendorRequest $request): JsonResponse
    {
        $this->authorize('create', VendorProfile::class);

        $vendor = VendorProfile::create($request->validated());

        return response()->json($vendor, Response::HTTP_CREATED);
    }

    public function show(VendorProfile $vendor): JsonResponse
    {
        $this->authorize('view', $vendor);

        return response()->json($vendor);
    }

    public function update(UpdateVendorRequest $request, VendorProfile $vendor): JsonResponse
    {
        $this->authorize('update', $vendor);

        $vendor->update($request->validated());

        return response()->json($vendor->refresh());
    }

    public function destroy(VendorProfile $vendor): Response
    {
        $this->authorize('delete', $vendor);

        $vendor->delete();

        return response()->noContent();
    }
}
