<?php

namespace App\Services\Pizzaria;

use App\Models\Category;
use App\Models\CategoryPizzaSizePrice;
use App\Models\OptionalFlow;
use App\Models\ProductVariation;
use App\Models\Store;
use App\Models\StorePizzaSize;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class PizzaConfigService
{
    public const MAX_SIZES = 3;

    public function __construct(private readonly PizzaOptionalFlowProvisioner $provisioner) {}

    // ── Tamanhos ─────────────────────────────────────────────────────────────

    public function listSizes(Store $store): Collection
    {
        return $store->pizzaSizes()->get();
    }

    public function createSize(Store $store, array $data): StorePizzaSize
    {
        $activeCount = $store->pizzaSizes()->count();

        if ($activeCount >= self::MAX_SIZES) {
            throw ValidationException::withMessages([
                'name' => 'Você já tem o máximo de '.self::MAX_SIZES.' tamanhos cadastrados.',
            ]);
        }

        $nextPosition = ((int) $store->pizzaSizes()->max('position')) + 1;

        return $store->pizzaSizes()->create([
            'name' => $data['name'],
            'slice_count' => $data['slice_count'] ?? null,
            'max_flavors' => $data['max_flavors'] ?? 1,
            'is_active' => $data['is_active'] ?? true,
            'position' => $nextPosition,
        ]);
    }

    public function updateSize(StorePizzaSize $size, array $data): StorePizzaSize
    {
        $size->fill([
            'name' => $data['name'] ?? $size->name,
            'slice_count' => $data['slice_count'] ?? $size->slice_count,
            'max_flavors' => $data['max_flavors'] ?? $size->max_flavors,
            'is_active' => $data['is_active'] ?? $size->is_active,
        ]);
        $size->save();

        return $size->refresh();
    }

    /**
     * O que seria afetado por uma exclusão — usado pra mostrar o aviso antes de confirmar.
     * Pedidos já realizados nunca são afetados (o histórico é imutável), então só olhamos
     * pra sabores atualmente configurados com esse tamanho.
     *
     * @return array{product_count: int, product_names: string[]}
     */
    public function sizeDeleteImpact(StorePizzaSize $size): array
    {
        $productNames = ProductVariation::query()
            ->where('store_pizza_size_id', $size->id)
            ->with('product:id,name')
            ->get()
            ->pluck('product.name')
            ->filter()
            ->unique()
            ->values()
            ->all();

        return ['product_count' => count($productNames), 'product_names' => $productNames];
    }

    public function deleteSize(StorePizzaSize $size): void
    {
        $impact = $this->sizeDeleteImpact($size);

        if ($impact['product_count'] > 0) {
            throw ValidationException::withMessages([
                'size' => 'Não é possível excluir este tamanho: '.$impact['product_count'].' produto(s) ainda o utilizam.',
            ]);
        }

        $size->delete();
    }

    public function reorderSizes(Store $store, array $orderedIds): void
    {
        foreach (array_values($orderedIds) as $index => $sizeId) {
            StorePizzaSize::query()
                ->where('store_id', $store->id)
                ->where('id', $sizeId)
                ->update(['position' => $index + 1]);
        }
    }

    // ── Preço padrão por categoria ───────────────────────────────────────────

    /**
     * Matriz categoria (pizza) x tamanho (ativo), pronta pra tabela do modal/config.
     *
     * @return array<int, array{category_id: int, category_name: string, prices: array<int, float|null>}>
     */
    public function categoryPriceMatrix(Store $store): array
    {
        $sizes = $this->listSizes($store)->where('is_active', true);
        $categories = Category::query()
            ->where('company_id', $store->company_id)
            ->where('category_type', 'pizza')
            ->orderBy('ordem_exibicao')
            ->get();

        $prices = CategoryPizzaSizePrice::query()
            ->whereIn('category_id', $categories->pluck('id'))
            ->get()
            ->groupBy('category_id');

        return $categories->map(function (Category $category) use ($sizes, $prices) {
            $categoryPrices = $prices->get($category->id, collect())->keyBy('store_pizza_size_id');

            return [
                'category_id' => $category->id,
                'category_name' => $category->name,
                'prices' => $sizes->mapWithKeys(fn (StorePizzaSize $size) => [
                    $size->id => $categoryPrices->has($size->id) ? (float) $categoryPrices->get($size->id)->price : null,
                ])->all(),
            ];
        })->values()->all();
    }

    /**
     * @param  array<int, float|null>  $sizePrices  [store_pizza_size_id => price]
     */
    public function updateCategoryPrices(Category $category, array $sizePrices): void
    {
        foreach ($sizePrices as $sizeId => $price) {
            CategoryPizzaSizePrice::query()->updateOrCreate(
                ['category_id' => $category->id, 'store_pizza_size_id' => (int) $sizeId],
                ['price' => $price],
            );
        }
    }

    // ── Config geral (toggles + regra de combo) ──────────────────────────────

    public function getConfig(Store $store): array
    {
        return [
            'sizes' => $this->listSizes($store),
            'category_prices' => $this->categoryPriceMatrix($store),
            'settings' => $store->pizzaSettings(),
        ];
    }

    public function updateSettings(Store $store, array $data): Store
    {
        $store->pizza_settings = array_replace_recursive($store->pizzaSettings(), $data);
        $store->save();

        return $store->refresh();
    }

    // ── Alertas (seção 6) ─────────────────────────────────────────────────────

    /**
     * @return array{sizes_deactivated: array, categories_incomplete: array, addons_deactivated_with_links: array}
     */
    public function reviewAlerts(Store $store): array
    {
        $inactiveSizesWithProducts = $store->pizzaSizes()
            ->where('is_active', false)
            ->get()
            ->map(fn (StorePizzaSize $size) => [
                'size' => $size->name,
                'impact' => $this->sizeDeleteImpact($size),
            ])
            ->filter(fn (array $row) => $row['impact']['product_count'] > 0)
            ->values()
            ->all();

        $activeSizeIds = $this->listSizes($store)->where('is_active', true)->pluck('id');
        $incompleteCategories = collect($this->categoryPriceMatrix($store))
            ->filter(function (array $row) use ($activeSizeIds) {
                foreach ($activeSizeIds as $sizeId) {
                    if (($row['prices'][$sizeId] ?? null) === null) {
                        return true;
                    }
                }

                return false;
            })
            ->values()
            ->all();

        $deactivatedAddonsWithLinks = OptionalFlow::query()
            ->where('store_id', $store->id)
            ->whereNotNull('feature_type')
            ->where('is_active', false)
            ->get()
            ->filter(function (OptionalFlow $flow) {
                return $flow->steps->flatMap(fn ($step) => $step->options)
                    ->flatMap(fn ($option) => $option->linkedProducts)
                    ->isNotEmpty();
            })
            ->map(fn (OptionalFlow $flow) => $flow->feature_type)
            ->values()
            ->all();

        return [
            'sizes_deactivated' => $inactiveSizesWithProducts,
            'categories_incomplete' => $incompleteCategories,
            'addons_deactivated_with_links' => $deactivatedAddonsWithLinks,
        ];
    }
}
