<?php

namespace Modules\ToolImages\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\ToolImages\Http\Requests\StoreToolImageRequest;
use Modules\ToolImages\Http\Requests\UpdateToolImageRequest;
use Modules\ToolImages\Http\Resources\ToolImagesResource;
use Modules\ToolImages\Models\ToolImage;
use Modules\ToolImages\Services\ToolImageService;

class ToolImagesController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', ToolImage::class);

        $query = ToolImage::query()
            ->with('tool')
            ->whereHas('tool', function (Builder $query): void {
                $query->visibleTo(request()->user());
            })
            ->orderBy('sort_order');

        return ToolImagesResource::collection($query->paginate())->response();
    }

    public function store(StoreToolImageRequest $request, ToolImageService $toolImages): JsonResponse
    {
        $this->authorize('create', ToolImage::class);

        $toolImage = $toolImages->store($request->validated());

        return (new ToolImagesResource($toolImage->load('tool')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(ToolImage $toolImage): JsonResponse
    {
        $this->authorize('view', $toolImage);

        return (new ToolImagesResource($toolImage->load('tool')))->response();
    }

    public function update(UpdateToolImageRequest $request, ToolImage $toolImage, ToolImageService $toolImages): JsonResponse
    {
        $this->authorize('update', $toolImage);

        $toolImage = $toolImages->update($toolImage, $request->validated());

        return (new ToolImagesResource($toolImage->refresh()->load('tool')))->response();
    }

    public function destroy(ToolImage $toolImage, ToolImageService $toolImages): Response
    {
        $this->authorize('delete', $toolImage);

        $toolImages->delete($toolImage);

        return response()->noContent();
    }
}
