<?php

namespace App\Services\Zapi\Builders;

use App\Models\Store;
use App\Models\Category; // 1. Correção: Importando Category
use Illuminate\Support\Collection;

class StoreCarouselBuilder
{
   /**
     * Transforma uma coleção de Lojas em Cards para o Carrossel da Z-API
     * 3. Agora público para ser usado pelo StoreHandle
     */
    public function buildStoreCards(Collection $stores): array
    {
        return $stores->map(function (Store $store) {
            return [
                'text' => $this->formatStoreCardText($store),
                // carousel_banner_url = logo composto num banner 1200x530 opaco (gerado por
                // `stores:generate-carousel-banners`) — evita o crop de object-fit: cover que
                // o client faz em cima do logo transparente cru.
                'image' => $store->carousel_banner_url ?? $store->logo_url ?? $store->cover_image_url ?? 'https://picsum.photos/seed/'.$store->slug.'/600/600',
                'buttons' => [
                    [
                        'id' => 'view_menu_' . $store->slug,
                        'label' => '📖 Ver Cardápio',
                        'type' => 'REPLY',
                    ],
                ],
            ];
        })->toArray();
    }

    public function buildCategoryHeader(Category $category): string
    {
        $emoji = match ($category->slug) {
            'cat_lanches'    => '🍔',
            'cat_pastel'     => '🥟',
            'cat_pizza'      => '🍕',
            'cat_acai'       => '🍇',
            'cat_refeicao'   => '🍽️',
            'cat_farmacia'   => '💊',
            'cat_padaria'    => '🥖',
            'cat_mercadinho' => '🛒',
            default          => '🏪',
        };

        $name = mb_convert_case(mb_strtolower((string) $category->name), MB_CASE_TITLE, 'UTF-8');

        return $emoji.' Lojas de '.$name.' — escolha e explore o cardápio';
    }
    
    public function buttonSlugFromCategorySlug(string $slug): string
    {
        if (str_starts_with($slug, 'cat_')) {
            return substr($slug, 4);
        }

        return $slug;
    }

    /**
     * Formata o texto descritivo que aparece no card da loja. Sem nota/ETA — não existe
     * fonte de dado real pra isso hoje (nenhuma tabela de avaliação no banco), então não
     * fabricamos número. `has_active_promotion` vem de `Store::scopeWithPromotionFlag()`.
     */
    public function formatStoreCardText(Store $store): string
    {
        $description = trim((string) ($store->description ?? 'O melhor da categoria no Zapediu.'));
        if (mb_strlen($description) > 70) {
            $description = rtrim(mb_substr($description, 0, 67)) . '...';
        }

        $badge = ((bool) ($store->getAttribute('has_active_promotion') ?? false)) ? "🔥 Ofertas hoje\n" : '';

        return $badge."{$store->name}\n\n" .
               "💬 \"{$description}\"";
    }

    public function getCategoryEmoji(?string $slug): string
    {
        return match ($slug) {
            'cat_lanches' => '🍔',
            'cat_pastel'  => '🥟',
            'cat_pizza'   => '🍕',
            'cat_acai'    => '🍇',
            'cat_refeicao' => '🍽️',
            'cat_farmacia' => '💊',
            'cat_padaria' => '🥖',
            'cat_mercadinho' => '🛒',
            'cat_japonesa' => '🍣',
            'cat_cafeteria' => '☕',
            'cat_gelateria' => '🍨',
            'cat_saudavel' => '🥗',
            'cat_mexicana' => '🌮',
            default       => '🏬',
        };
    }

    public function buildMoreStoresCard(int $nextOffset): array
    {
        return [
            'text' => 'Ver mais lojas disponíveis',
            'image' => (string) config('services.zapi.flow_more_image', 'https://picsum.photos/seed/mais-lojas/600/600'),
            'buttons' => [
                [
                    'id' => 'view_more_' . $nextOffset,
                    'label' => 'Ver mais',
                    'type' => 'REPLY',
                ],
            ],
        ];
    }
}
