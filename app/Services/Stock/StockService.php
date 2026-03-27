<?php

namespace App\Services\Stock;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Support\Audit\AuditLogger;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class StockService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * Paginated product list with search and category filter.
     *
     * Query params:
     *   search      – partial match on name or description
     *   category_id – exact category FK
     *   is_active   – 1 / 0 / (omit = all)
     *   per_page    – default 15
     */
    public function listProducts(Request $request): LengthAwarePaginator
    {
        return Product::query()
            ->with(['category:id,name,color,slug', 'variations:id,product_id,name,price,stock_quantity,is_default,is_active'])
            ->when(
                $request->filled('search'),
                fn ($q) => $q->where(function ($inner) use ($request): void {
                    $term = '%'.$request->string('search')->toString().'%';
                    $inner->where('products.name', 'like', $term)
                          ->orWhere('products.description', 'like', $term);
                })
            )
            ->when(
                $request->filled('category_id'),
                fn ($q) => $q->where('products.category_id', (int) $request->query('category_id'))
            )
            ->when(
                $request->has('is_active'),
                fn ($q) => $q->where('products.is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN))
            )
            ->orderBy('products.name')
            ->paginate((int) $request->query('per_page', 15));
    }

    /**
     * Create a product with optional image and variations.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $variations
     */
    public function createProduct(array $data, array $variations, ?UploadedFile $image, Request $request): Product
    {
        return DB::transaction(function () use ($data, $variations, $image, $request): Product {
            $product = Product::query()->create($data);

            if ($image !== null) {
                $product->addMedia($image)->toMediaCollection('products');
            }

            $this->syncVariations($product, $variations);

            $this->auditLogger->log('product.created', [
                'entity_type' => Product::class,
                'entity_id'   => $product->id,
                'changes'     => $product->toArray(),
            ], $request);

            return $product->load(['category:id,name,color,slug', 'variations']);
        });
    }

    /**
     * Update a product, replacing its image if a new one is provided.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $variations
     */
    public function updateProduct(Product $product, array $data, array $variations, ?UploadedFile $image, Request $request): Product
    {
        return DB::transaction(function () use ($product, $data, $variations, $image, $request): Product {
            $product->fill($data)->save();

            if ($image !== null) {
                $product->clearMediaCollection('products');
                $product->addMedia($image)->toMediaCollection('products');
            }

            $this->syncVariations($product, $variations);

            $this->auditLogger->log('product.updated', [
                'entity_type' => Product::class,
                'entity_id'   => $product->id,
                'changes'     => $product->toArray(),
            ], $request);

            return $product->refresh()->load(['category:id,name,color,slug', 'variations']);
        });
    }

    /**
     * Delete a product and its associated media.
     */
    public function deleteProduct(Product $product, Request $request): void
    {
        $this->auditLogger->log('product.deleted', [
            'entity_type' => Product::class,
            'entity_id'   => $product->id,
            'changes'     => $product->toArray(),
        ], $request);

        $product->clearMediaCollection('products');
        $product->delete();
    }

    /**
     * Return all active categories for the current tenant, ordered by name.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Category>
     */
    public function listCategories(): \Illuminate\Database\Eloquent\Collection
    {
        return Category::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'color', 'is_active']);
    }

    /**
     * Create a new category for the current tenant.
     *
     * @param  array<string, mixed>  $data
     */
    public function createCategory(array $data): Category
    {
        return Category::query()->create($data);
    }

    /**
     * Update a category's name and/or color.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateCategory(Category $category, array $data): Category
    {
        $category->fill($data)->save();

        return $category->refresh();
    }

    /**
     * Delete a category; products that belong to it will have category_id set to null.
     */
    public function deleteCategory(Category $category): void
    {
        $category->delete();
    }

    /**
     * Upsert product variations and remove deleted ones.
     *
     * @param  array<int, array<string, mixed>>  $variations
     */
    private function syncVariations(Product $product, array $variations): void
    {
        if ($variations === []) {
            return;
        }

        $existingIds = [];

        foreach ($variations as $variationInput) {
            $payload = Arr::only($variationInput, [
                'name',
                'sku',
                'price',
                'stock_quantity',
                'attributes',
                'is_default',
                'is_active',
            ]);

            $payload['stock_quantity'] = (int) ($payload['stock_quantity'] ?? 0);
            $payload['is_default']     = (bool) ($payload['is_default'] ?? false);
            $payload['is_active']      = array_key_exists('is_active', $payload) ? (bool) $payload['is_active'] : true;

            if (isset($variationInput['id'])) {
                $variation = ProductVariation::query()
                    ->where('product_id', $product->id)
                    ->find((int) $variationInput['id']);

                if ($variation !== null) {
                    $variation->fill($payload)->save();
                    $existingIds[] = $variation->id;
                    continue;
                }
            }

            $variation     = $product->variations()->create($payload);
            $existingIds[] = $variation->id;
        }

        $product->variations()->whereNotIn('id', $existingIds)->delete();
    }
}
