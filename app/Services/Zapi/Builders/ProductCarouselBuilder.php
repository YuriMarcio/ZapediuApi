<?php

namespace App\Services\Zapi\Builders;

use App\Models\Store;
use App\Models\Product;
use Illuminate\Support\Str;

class ProductCarouselBuilder
{
    public function formatProductCardText(Product $product, Store $store): string
    {
        $name = trim((string) $product->name);
        $description = $this->normalizeProductDescription((string) ($product->description ?? 'Produto saboroso.'));

        return $name.' '.$this->productEmoji($product, $store)."\n\n"
            .$this->formatPriceLine($product)."\n\n"
            .'💬 "'.$description.'"';
    }

    public function formatPriceLine(Product $product): string
    {
        $price = (float) $product->price;
        $promotionalPrice = $product->promotional_price !== null ? (float) $product->promotional_price : null;

        if ($promotionalPrice === null || $promotionalPrice >= $price) {
            return '🏷️ Por: '.$this->formatMoney($price);
        }

        $discountPct = (int) round((1 - ($promotionalPrice / $price)) * 100);

        return '~De: '.$this->formatMoney($price)."~\n"
            .'🔥 Por: '.$this->formatMoney($promotionalPrice)." ({$discountPct}% OFF)";
    }

    private function formatMoney(float $value): string
    {
        return 'R$ '.number_format($value, 2, ',', '.');
    }

    public function normalizeProductDescription(string $description): string
    {
        $description = trim($description);

        if ($description === '') {
            return 'Produto saboroso.';
        }

        if (! str_ends_with($description, '.') && ! str_ends_with($description, '!') && ! str_ends_with($description, '?')) {
            $description .= '.';
        }

        return $description;
    }

    public function productEmoji(Product $product, Store $store): string
    {
        $slug = (string) ($store->category?->slug ?? '');

        return match ($slug) {
            'cat_lanches' => '🍔',
            'cat_pastel' => '🥟',
            'cat_pizza' => '🍕',
            'cat_acai' => '🍇',
            'cat_refeicao' => '🍽️',
            'cat_farmacia' => '💊',
            'cat_padaria' => '🥖',
            'cat_mercadinho' => '🛒',
            default => '🍽️',
        };
    }

    public function buildMenuIntroMessage(Store $store): string
    {
        return '📖 Cardápio: '.$store->name." 📖\n\n"
            .'Deslize para o lado, escolha o seu pedido e clique em Adicionar'
            ."\n";
    }

}
