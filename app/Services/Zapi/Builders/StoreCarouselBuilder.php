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
                'image' => $store->cover_image_path ?? $store->logo_path ?? 'https://picsum.photos/seed/'.$store->slug.'/600/600',
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

    /**
     * 2. Consolidação: Unificando buildStoreRating e calculateFakeRating
     */
    public function calculateRating(Store $store): string
    {
        $seed = abs(crc32((string) ($store->slug ?: $store->id)));
        return number_format(4.2 + (($seed % 8) / 10), 1, '.', '');
    }

    /**
     * 2. Consolidação: Unificando buildStoreEta e estimateEta
     */
    public function estimateEta(Store $store): string
    {
        $seed = abs(crc32((string) ($store->slug ?: $store->id)));
        $etas = ['15-25 min', '20-30 min', '25-35 min', '30-40 min'];

        return $etas[$seed % count($etas)];
    }


    public function buildStoreRating(Store $store): string
    {
        $seed = abs(crc32((string) ($store->slug ?: $store->id)));

        return number_format(4.2 + (($seed % 8) / 10), 1, '.', '');
    }

    public function buildStoreEta(Store $store): string
    {
        $seed = abs(crc32((string) ($store->slug ?: $store->id)));
        $etas = ['15-25 min', '20-30 min', '25-35 min', '30-40 min'];

        return $etas[$seed % count($etas)];
    }
    public function buildStoreLogisticsLine(mixed $store): string
    {
        if (is_array($store)) {
            $distance = (string) ($store['distance'] ?? '2,1 km');
            $shipping = (string) ($store['shipping'] ?? 'Frete Gratis');
            $eta = (string) ($store['eta'] ?? '30-40 min');

            return $distance.' • '.$shipping.' • '.$eta;
        }

        if ($store instanceof Store) {
            $seed = abs(crc32((string) ($store->slug ?: $store->id)));
            $distances = ['0,9 km', '1,4 km', '2,1 km', '2,8 km', '3,6 km'];
            $shippings = ['Frete Gratis', 'Frete R$ 3,99', 'Frete R$ 4,99', 'Frete R$ 6,49'];
            $etas = ['15-25 min', '20-30 min', '25-35 min', '30-40 min'];

            $distance = $distances[$seed % count($distances)];
            $shipping = $shippings[$seed % count($shippings)];
            $eta = $etas[$seed % count($etas)];

            return $distance.' • '.$shipping.' • '.$eta;
        }

        $distance = '2,1 km';
        $shipping = 'Frete Gratis';
        $eta = '30-40 min';

        return $distance.' • '.$shipping.' • '.$eta;
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

    public function storeCategoryEmoji(Store $store): string
    {
        return match ((string) ($store->category?->slug ?? '')) {
            'cat_lanches' => '🍔',
            'cat_pastel' => '🥟',
            'cat_pizza' => '🍕',
            'cat_acai' => '🍇',
            'cat_refeicao' => '🍽️',
            'cat_farmacia' => '💊',
            'cat_padaria' => '🥖',
            'cat_mercadinho' => '🛒',
            default => '🏬',
        };
    }

    /**
     * Formata o texto descritivo que aparece no card da loja
     */
    public function formatStoreCardText(Store $store): string
    {
        $emoji = $this->getCategoryEmoji($store->category?->slug);
        $rating = $this->calculateRating($store);
        $eta = $this->estimateEta($store);

        $description = trim((string) ($store->description ?? 'O melhor da categoria no Zapediu.'));
        if (mb_strlen($description) > 70) {
            $description = rtrim(mb_substr($description, 0, 67)) . '...';
        }

        return "{$store->name} {$emoji}\n" .
               "⭐ {$rating} | 🛵 {$eta}\n\n" .
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
            default       => '🏬',
        };
    }

    public function calculateFakeRating(Store $store): string
    {
        $seed = abs(crc32((string) $store->slug));
        return number_format(4.2 + (($seed % 8) / 10), 1, '.', '');
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
