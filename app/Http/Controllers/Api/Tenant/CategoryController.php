<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Services\Stock\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function __construct(private readonly StockService $stockService) {}

    /**
     * GET /tenant/categories
     * Returns all active categories for the authenticated company.
     */
    public function index(): JsonResponse
    {
        return response()->json($this->stockService->listCategories());
    }

    /**
     * POST /tenant/categories
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'  => ['required', 'string', 'max:80'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{3,6}$/'],
        ]);

        $category = $this->stockService->createCategory($data);

        return response()->json($category, 201);
    }

    /**
     * PUT /tenant/categories/{category}
     */
    public function update(Request $request, Category $category): JsonResponse
    {
        $data = $request->validate([
            'name'      => ['sometimes', 'required', 'string', 'max:80'],
            'color'     => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{3,6}$/'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $category = $this->stockService->updateCategory($category, $data);

        return response()->json($category);
    }

    /**
     * DELETE /tenant/categories/{category}
     */
    public function destroy(Category $category): JsonResponse
    {
        $this->stockService->deleteCategory($category);

        return response()->json(['message' => 'Categoria removida com sucesso.']);
    }
}
