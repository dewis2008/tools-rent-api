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
        return response()->json(VendorProfile::query()->with('user')->latest()->paginate());
    }

    public function store(StoreVendorRequest $request): JsonResponse
    {
        $vendor = VendorProfile::create($request->validated());

        return response()->json($vendor->load('user'), Response::HTTP_CREATED);
    }

    public function show(VendorProfile $vendor): JsonResponse
    {
        return response()->json($vendor->load('user'));
    }

    public function update(UpdateVendorRequest $request, VendorProfile $vendor): JsonResponse
    {
        $vendor->update($request->validated());

        return response()->json($vendor->refresh()->load('user'));
    }

    public function destroy(VendorProfile $vendor): Response
    {
        $vendor->delete();

        return response()->noContent();
    }
}
