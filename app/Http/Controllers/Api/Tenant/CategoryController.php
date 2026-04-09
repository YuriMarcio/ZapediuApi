<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreCategoryRequest;
use App\Http\Requests\Api\UpdateCategoryRequest;
use App\Models\Category;
use App\Services\Stock\StockService;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    public function __construct(private readonly StockService $stockService) {}

    /**
     * GET /tenant/categories
     * Returns all active categories for the authenticated company.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->stockService->listCategories()->map(fn (Category $category) => $this->transformCategory($category)),
        ]);
    }

    /**
     * POST /tenant/categories
     */
    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $category = $this->stockService->createCategory(
            $request->validated(),
            $request->file('image'),
            $request,
        );

        return response()->json([
            'data' => $this->transformCategory($category),
        ], 201);
    }

    /**
     * PUT /tenant/categories/{category}
     */
    public function update(UpdateCategoryRequest $request, Category $category): JsonResponse
    {
        $category = $this->stockService->updateCategory(
            $category,
            $request->validated(),
            $request->file('image'),
            $request,
        );

        return response()->json([
            'data' => $this->transformCategory($category),
        ]);
    }

    /**
     * DELETE /tenant/categories/{category}
     */
    public function destroy(Category $category): JsonResponse
    {
        $this->stockService->deleteCategory($category);

        return response()->json(['message' => 'Categoria removida com sucesso.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function transformCategory(Category $category): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'icon' => $category->icon,
            'image_url' => $category->image_url,
            'color' => $category->color,
            'sort_order' => (int) ($category->ordem_exibicao ?? 0),
            'is_active' => (bool) $category->is_active,
            'products_count' => (int) ($category->products_count ?? 0),
            'created_at' => optional($category->created_at)?->toJSON(),
            'updated_at' => optional($category->updated_at)?->toJSON(),
        ];
    }
}
