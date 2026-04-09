<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreProductRequest;
use App\Http\Requests\Api\UpdateProductRequest;
use App\Models\Product;
use App\Services\Stock\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function __construct(private readonly StockService $stockService) {}

    /**
     * GET /tenant/products
     *
     * Query params:
     *   search      – partial match on name/description
     *   category_id – filter by category
     *   is_active   – 1 / 0 (omit = all)
     *   per_page    – default 15
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->stockService->listProducts($request));
    }

    /**
     * POST /tenant/products
     */
    public function store(StoreProductRequest $request): JsonResponse
    {
        $data       = $request->safe()->except('variations', 'image');
        $variations = (array) $request->input('variations', []);
        $image      = $request->file('image');

        $product = $this->stockService->createProduct($data, $variations, $image, $request);

        return response()->json($product, 201);
    }

    /**
     * GET /tenant/products/{product}
     */
    public function show(Product $product): JsonResponse
    {
        return response()->json(
            $product->load([
                'category:id,name,color,slug',
                'selectionGroup:id,name,display_type,is_required,is_active',
                'selectionGroup.options:id,selection_group_id,label,description,price,position,is_active',
                'variationGroup:id,name,required',
                'variationGroup.options:id,variation_group_id,name,price,sort_order',
                'variations',
            ])
        );
    }

    /**
     * PUT /tenant/products/{product}
     */
    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $data       = $request->safe()->except('variations', 'image');
        $variations = (array) $request->input('variations', []);
        $image      = $request->file('image');

        $product = $this->stockService->updateProduct($product, $data, $variations, $image, $request);

        return response()->json($product);
    }

    /**
     * DELETE /tenant/products/{product}
     */
    public function destroy(Product $product, Request $request): JsonResponse
    {
        $this->stockService->deleteProduct($product, $request);

        return response()->json(['message' => 'Produto removido com sucesso.']);
    }
}
