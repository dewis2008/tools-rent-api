<?php

namespace Modules\Categories\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\Categories\Http\Requests\StoreCategoryRequest;
use Modules\Categories\Http\Requests\UpdateCategoryRequest;
use Modules\Categories\Models\Category;

class CategoriesController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Category::class);

        return response()->json(Category::query()->latest()->paginate());
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $this->authorize('create', Category::class);

        $category = Category::create($request->validated());

        return response()->json($category, Response::HTTP_CREATED);
    }

    public function show(Category $category): JsonResponse
    {
        $this->authorize('view', $category);

        return response()->json($category);
    }

    public function update(UpdateCategoryRequest $request, Category $category): JsonResponse
    {
        $this->authorize('update', $category);

        $category->update($request->validated());

        return response()->json($category->refresh());
    }

    public function destroy(Category $category): Response
    {
        $this->authorize('delete', $category);

        $category->delete();

        return response()->noContent();
    }
}
