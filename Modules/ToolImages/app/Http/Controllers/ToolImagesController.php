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
        $this->authorize('viewAny', ToolImage::class);

        return response()->json(ToolImage::query()->with('tool')->orderBy('sort_order')->paginate());
    }

    public function store(StoreToolImageRequest $request): JsonResponse
    {
        $this->authorize('create', ToolImage::class);

        $toolImage = ToolImage::create($request->validated());

        return response()->json($toolImage->load('tool'), Response::HTTP_CREATED);
    }

    public function show(ToolImage $toolImage): JsonResponse
    {
        $this->authorize('view', $toolImage);

        return response()->json($toolImage->load('tool'));
    }

    public function update(UpdateToolImageRequest $request, ToolImage $toolImage): JsonResponse
    {
        $this->authorize('update', $toolImage);

        $toolImage->update($request->validated());

        return response()->json($toolImage->refresh()->load('tool'));
    }

    public function destroy(ToolImage $toolImage): Response
    {
        $this->authorize('delete', $toolImage);

        $toolImage->delete();

        return response()->noContent();
    }
}
