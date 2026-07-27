<?php

namespace App\Services\Pizzaria;

use App\Models\CategoryPizzaSizePrice;
use App\Models\OptionalFlowStepOption;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\StorePizzaSize;

class PizzaPricingResolver
{
    /**
     * Hierarquia de preço de um sabor num tamanho: (futuro: promoção >) preço específico
     * do produto > preço padrão da categoria > indisponível.
     *
     * @return array{price: float|null, source: 'override'|'category'|'unavailable'}
     */
    public function resolveFlavorPrice(Product $product, StorePizzaSize $size): array
    {
        // Gancho para o futuro: uma promoção ativa para (product/category, size) entraria
        // aqui, antes do preço específico do produto, retornando source => 'promotion'.

        $variation = ProductVariation::query()
            ->where('product_id', $product->id)
            ->where('store_pizza_size_id', $size->id)
            ->first();

        if ($variation !== null && $variation->price_mode === 'override' && $variation->override_price !== null) {
            return ['price' => (float) $variation->override_price, 'source' => 'override'];
        }

        $categoryPrice = $product->category_id !== null
            ? CategoryPizzaSizePrice::query()
                ->where('category_id', $product->category_id)
                ->where('store_pizza_size_id', $size->id)
                ->value('price')
            : null;

        if ($categoryPrice !== null) {
            return ['price' => (float) $categoryPrice, 'source' => 'category'];
        }

        return ['price' => null, 'source' => 'unavailable'];
    }

    /**
     * Preço final de uma pizza com múltiplos sabores, conforme a regra da loja.
     *
     * @param  float[]  $flavorPrices
     */
    public function resolveComboPrice(array $flavorPrices, string $rule): float
    {
        $flavorPrices = array_values(array_filter($flavorPrices, fn ($price) => $price !== null));

        if ($flavorPrices === []) {
            return 0.0;
        }

        return $rule === 'average'
            ? round(array_sum($flavorPrices) / count($flavorPrices), 2)
            : max($flavorPrices);
    }

    /**
     * Preço efetivo de um item de opcional (borda/ingrediente/molho), único ou por tamanho.
     */
    public function resolveAddOnPrice(OptionalFlowStepOption $option, ?StorePizzaSize $size): float
    {
        if ($option->pricing_mode === 'per_size' && $size !== null) {
            $sizePrice = $option->sizePrices()->where('store_pizza_size_id', $size->id)->value('price');

            if ($sizePrice !== null) {
                return (float) $sizePrice;
            }
        }

        return (float) ($option->merchant_price ?? $option->base_price ?? $option->price);
    }
}
