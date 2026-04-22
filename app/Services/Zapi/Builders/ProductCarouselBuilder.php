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
        $price = 'R$ '.number_format((float) $product->price, 2, ',', '.');
        $description = $this->normalizeProductDescription((string) ($product->description ?? 'Produto saboroso.'));

        return $name.' '.$this->productEmoji($product, $store)."\n\n"
            .'🏷️ Por: '.$price."\n\n"
            .'💬 "'.$description.'"';
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
