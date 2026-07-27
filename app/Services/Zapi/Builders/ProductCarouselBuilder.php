<?php

namespace App\Services\Zapi\Builders;

use App\Models\Store;
use App\Models\Product;
use Illuminate\Support\Str;

class ProductCarouselBuilder
{
    /**
     * Card de produto no formato do documento (§5/§5.3):
     *
     *   🍔 Hambúrguer artesanal com bacon crocante e cheddar
     *   _Acompanha: Pão brioche, carne artesanal, bacon crocante, cheddar_
     *   R$ 29,90
     *
     * Em promoção, badge de desconto na primeira linha + preço riscado:
     *
     *   🔥 -20%
     *   X-Tudo Especial
     *   _Acompanha: ..._
     *   De ~R$ 50,00~ por R$ 40,00
     */
    /**
     * `$showStoreName` (spec §1.1.b): carrossel de busca por produto cruza várias lojas, então
     * o nome da loja entra como linha de texto — o header do card só suporta 1 imagem, já
     * ocupada pela foto do produto. No carrossel normal (dentro de uma loja só) fica `false`,
     * já que o cliente já sabe em qual loja está.
     */
    public function formatProductCardText(Product $product, Store $store, bool $showStoreName = false): string
    {
        $lines = [];
        $promo = $this->promotionInfo($product);

        if ($promo !== null) {
            $lines[] = '🔥 -'.$promo['discount_pct'].'%';
            $lines[] = trim((string) $product->name);
        } else {
            $lines[] = $this->productEmoji($product, $store).' '.trim((string) $product->name);
        }

        $description = trim((string) ($product->description ?? ''));
        if ($description !== '') {
            $lines[] = '_Acompanha: '.rtrim($description, '.').'_';
        }

        if ($showStoreName) {
            $lines[] = '🏪 '.trim((string) $store->name);
        }

        $lines[] = $promo !== null
            ? 'De ~'.$this->formatMoney($promo['price_from']).'~ por '.$this->formatMoney($promo['price_final'])
            : $this->formatMoney((float) $product->price);

        return implode("\n", $lines);
    }

    /**
     * Preço efetivo de venda (promocional quando ativo) — usado no label do botão
     * "🛒 Adicionar por R$ X" (§5.1).
     */
    public function effectivePrice(Product $product): float
    {
        $promo = $this->promotionInfo($product);

        return $promo !== null ? $promo['price_final'] : (float) $product->price;
    }

    /**
     * @return array{price_from: float, price_final: float, discount_pct: int}|null
     */
    private function promotionInfo(Product $product): ?array
    {
        $price = (float) $product->price;
        $promotionalPrice = $product->promotional_price !== null ? (float) $product->promotional_price : null;

        if ($promotionalPrice === null || $promotionalPrice >= $price) {
            return null;
        }

        return [
            'price_from' => $price,
            'price_final' => $promotionalPrice,
            'discount_pct' => (int) round((1 - ($promotionalPrice / $price)) * 100),
        ];
    }

    private function formatMoney(float $value): string
    {
        return 'R$ '.number_format($value, 2, ',', '.');
    }

    public function productEmoji(Product $product, Store $store): string
    {
        $slug = (string) ($store->category?->slug ?? '');

        return match ($slug) {
            'cat_lanches' => '🍔',
            'cat_pastel' => '🥟',
            'cat_pizza' => '🍕',
            'cat_acai' => '🍧',
            'cat_refeicao' => '🍽️',
            'cat_farmacia' => '💊',
            'cat_padaria' => '🥖',
            'cat_mercadinho' => '🛒',
            'cat_japonesa' => '🍣',
            'cat_cafeteria' => '☕',
            'cat_gelateria' => '🍨',
            'cat_saudavel' => '🥗',
            'cat_mexicana' => '🌮',
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
