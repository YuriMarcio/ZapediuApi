<?php

namespace App\Services\Stock;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Services\ImageUploadService;
use App\Support\Audit\AuditLogger;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class StockService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly ImageUploadService $imageUploader,
    ) {}

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
            ->with([
                'category:id,name,color,slug',
                'selectionGroup:id,name,display_type,is_required,is_active',
                'selectionGroup.options:id,selection_group_id,label,description,price,position,is_active',
                'variationGroup:id,name,required',
                'variationGroup.options:id,variation_group_id,name,price,sort_order',
                'variations:id,product_id,name,price,stock_quantity,is_default,is_active',
            ])
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

            return $product->load([
                'category:id,name,color,slug',
                'selectionGroup:id,name,display_type,is_required,is_active',
                'selectionGroup.options:id,selection_group_id,label,description,price,position,is_active',
                'variationGroup:id,name,required',
                'variationGroup.options:id,variation_group_id,name,price,sort_order',
                'variations',
            ]);
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

            return $product->refresh()->load([
                'category:id,name,color,slug',
                'selectionGroup:id,name,display_type,is_required,is_active',
                'selectionGroup.options:id,selection_group_id,label,description,price,position,is_active',
                'variationGroup:id,name,required',
                'variationGroup.options:id,variation_group_id,name,price,sort_order',
                'variations',
            ]);
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
            ->withCount('products')
            ->orderBy('ordem_exibicao')
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'icon',
                'slug',
                'image_url',
                'ordem_exibicao',
                'color',
                'is_active',
                'created_at',
                'updated_at',
            ]);
    }

    /**
     * Create a new category for the current tenant.
     *
     * @param  array<string, mixed>  $data
     */
    public function createCategory(array $data, ?UploadedFile $image, Request $request): Category
    {
        return DB::transaction(function () use ($data, $image, $request): Category {
            $payload = $this->normalizeCategoryPayload($data);

            if ($image !== null) {
                $payload['image_url'] = $this->imageUploader->upload($image, 'categories');
            }

            $category = Category::query()->create($payload);

            $this->auditLogger->log('category.created', [
                'entity_type' => Category::class,
                'entity_id' => $category->id,
                'changes' => $category->toArray(),
            ], $request);

            return $category->refresh()->loadCount('products');
        });
    }

    /**
     * Update a category's name and/or color.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateCategory(Category $category, array $data, ?UploadedFile $image, Request $request): Category
    {
        return DB::transaction(function () use ($category, $data, $image, $request): Category {
            $payload = $this->normalizeCategoryPayload($data);

            if ($image !== null) {
                $this->deleteCategoryImageIfLocal($category->image_url);
                $payload['image_url'] = $this->imageUploader->upload($image, 'categories');
            }

            $category->fill($payload)->save();

            $this->auditLogger->log('category.updated', [
                'entity_type' => Category::class,
                'entity_id' => $category->id,
                'changes' => $category->toArray(),
            ], $request);

            return $category->refresh()->loadCount('products');
        });
    }

    /**
     * Delete a category; products that belong to it will have category_id set to null.
     */
    public function deleteCategory(Category $category): void
    {
        $this->deleteCategoryImageIfLocal($category->image_url);
        $category->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeCategoryPayload(array $data): array
    {
        if (array_key_exists('sort_order', $data)) {
            $data['ordem_exibicao'] = (int) $data['sort_order'];
            unset($data['sort_order']);
        }

        return $data;
    }

    private function deleteCategoryImageIfLocal(?string $imageUrl): void
    {
        if ($imageUrl === null || $imageUrl === '') {
            return;
        }

        // R2 URL — delete from R2
        $r2PublicUrl = rtrim((string) config('filesystems.disks.r2.url', ''), '/');
        if ($r2PublicUrl !== '' && str_starts_with($imageUrl, $r2PublicUrl)) {
            $this->imageUploader->delete($imageUrl);

            return;
        }

        // Legacy local storage URL — delete from public disk
        $prefix = url('/storage/');
        if (str_starts_with($imageUrl, $prefix)) {
            $path = ltrim(substr($imageUrl, strlen($prefix)), '/');
            if ($path !== '') {
                Storage::disk('public')->delete($path);
            }
        }
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
