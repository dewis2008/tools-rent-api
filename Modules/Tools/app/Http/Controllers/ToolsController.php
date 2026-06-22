<?php

namespace Modules\Tools\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\Tools\Http\Requests\StoreToolRequest;
use Modules\Tools\Http\Requests\UpdateToolRequest;
use Modules\Tools\Models\Tool;

class ToolsController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Tool::query()->with(['vendor', 'category'])->latest()->paginate());
    }

    public function store(StoreToolRequest $request): JsonResponse
    {
        $tool = Tool::create($request->validated());

        return response()->json($tool->load(['vendor', 'category']), Response::HTTP_CREATED);
    }

    public function show(Tool $tool): JsonResponse
    {
        return response()->json($tool->load(['vendor', 'category', 'images']));
    }

    public function update(UpdateToolRequest $request, Tool $tool): JsonResponse
    {
        $tool->update($request->validated());

        return response()->json($tool->refresh()->load(['vendor', 'category', 'images']));
    }

    public function destroy(Tool $tool): Response
    {
        $tool->delete();

        return response()->noContent();
    }
}
