<?php

namespace Modules\ToolImages\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\ToolImages\Http\Requests\StoreToolImageRequest;
use Modules\ToolImages\Http\Requests\UpdateToolImageRequest;
use Modules\ToolImages\Models\ToolImage;

class ToolImagesController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(ToolImage::query()->with('tool')->orderBy('sort_order')->paginate());
    }

    public function store(StoreToolImageRequest $request): JsonResponse
    {
        $toolImage = ToolImage::create($request->validated());

        return response()->json($toolImage->load('tool'), Response::HTTP_CREATED);
    }

    public function show(ToolImage $toolImage): JsonResponse
    {
        return response()->json($toolImage->load('tool'));
    }

    public function update(UpdateToolImageRequest $request, ToolImage $toolImage): JsonResponse
    {
        $toolImage->update($request->validated());

        return response()->json($toolImage->refresh()->load('tool'));
    }

    public function destroy(ToolImage $toolImage): Response
    {
        $toolImage->delete();

        return response()->noContent();
    }
}
