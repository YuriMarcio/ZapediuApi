<?php

namespace App\Services\Zapi\Handlers;

use App\Models\Category;
use App\Models\Store;
use App\Services\Zapi\Builders\StoreCarouselBuilder;
use App\Services\Zapi\Flows\FlowManager;
use App\Services\Zapi\Support\StoreSearch;
use App\Services\Whatsapp\WhatsAppClientInterface;
use Illuminate\Support\Facades\Log;

class CategoriesHandle
{
    public function __construct(
        private FlowManager $flow,
        private StoreSearch $search,
        private WhatsAppClientInterface $zapiClient,
        // Injetando StoreHandle para resolver o problema 4
        private StoreHandle $storeHandle,
        private StoreCarouselBuilder $carouselBuilder
    ) {
    }

    /**
     * Auxiliar para salvar estado (Problema 2)
     */
    private function saveFlowState(string $phone, array $state): void
    {
        $this->flow->saveState($phone, $state);
    }

    /**
       * Resolve o problema 4: Delega para o StoreHandle que possui a lógica de paginação
       */
    public function returnToStores(string $phone): bool
    {
        $state = $this->flow->getState($phone);
        $state['selected_store_id'] = null;
        $state['store_offset'] = 0;
        $state['store_results'] = null;
        $state['last_search'] = null;
        $this->saveFlowState($phone, $state);

        return $this->storeHandle->sendStoresPage($phone, 0);
    }

   public function sendCategoryStores(string $phone, string $categoryId): bool
    {
        // whereNull('store_id') — só a categoria "global" de classificação da loja (ex.:
        // cat_pizza), nunca uma categoria de cardápio interna de uma loja (ex.: "Combos" da
        // Forno Veloz), que teria zero lojas vinculadas por category_id.
        $category = Category::query()
            ->where('is_active', true)
            ->whereNull('store_id')
            ->where('slug', $categoryId)
            ->first();

        if ($category === null) {
            return false;
        }

        $limit = 9;
        $stores = Store::query()
            ->where('is_active', true)
            ->where('category_id', $category->id)
            ->with(['company:id,is_open', 'category:id,slug,name'])
            ->withPromotionFlag()
            ->orderBy('name')
            ->get()
            ->filter(fn (Store $store): bool => $store->isOpenNow())
            ->take($limit)
            ->values();

        return $this->sendStoreCarouselFromCollection(
            $phone,
            $stores,
            $this->carouselBuilder->buildCategoryHeader($category),
            true
        );
    }

    public function sendCategoriesCarousel(string $phone): bool
    {
        // whereNull('store_id') — só o catálogo global de classificação (cat_lanches,
        // cat_pizza, cat_acai...), nunca as categorias de cardápio internas de cada loja
        // (ex.: "Combos", "Bebidas"), que não têm sentido nesse carrossel de entrada.
        $categories = Category::query()
            ->where('is_active', true)
            ->whereNull('store_id')
            ->orderBy('ordem_exibicao')
            ->limit(10)
            ->get();

        if ($categories->isEmpty()) {
            return $this->sendAllStoresFallback($phone, 'Nenhuma categoria encontrada no momento.');
        }

        $cards = [];
        foreach ($categories as $category) {
            $emoji = $this->carouselBuilder->getCategoryEmoji((string) $category->slug);
            $name = mb_convert_case(mb_strtolower((string) $category->name), MB_CASE_TITLE, 'UTF-8');

            $cards[] = [
                'text' => "{$emoji} {$name}",
                'image' => $category->image_url ?: 'https://picsum.photos/seed/cat-'.$category->id.'/600/600',
                'buttons' => [
                    [
                        'id' => 'buscar_cat_' . $this->carouselBuilder->buttonSlugFromCategorySlug((string) $category->slug),
                        'label' => '🏪 Ver Lojas',
                        'type' => 'REPLY',
                    ],
                ],
            ];
        }

        try {
            $this->zapiClient->sendCarousel($phone, '🍔 *Escolha uma categoria:*', $cards);
            return true;
        } catch (\Throwable $exception) {
            Log::warning('Failed to send categories carousel.', ['error' => $exception->getMessage()]);
            return false;
        }
    }

    public function handleCategorySearch(string $phone, string $buttonId): bool
    {
        $slugPart = substr($buttonId, strlen('buscar_cat_'));
        $categorySlug = $this->resolveCategorySlugFromButtonSlug($slugPart);

        if (!$categorySlug) return false;

        return $this->sendCategoryStores($phone, $categorySlug);
    }

    private function sendStoresByCategoryButtonPayload(string $phone, string $buttonId): bool
    {
        $slugPart = substr($buttonId, strlen('buscar_cat_'));

        if (! is_string($slugPart) || trim($slugPart) === '') {
            return false;
        }

        $categorySlug = $this->resolveCategorySlugFromButtonSlug($slugPart);

        if ($categorySlug === null) {
            return false;
        }

        return $this->sendCategoryStores($phone, $categorySlug);
    }

    private function sendStoreCarouselFromCollection(string $phone, $stores, string $message, bool $appendMoreCard = false): bool
    {
        if ($stores->isEmpty()) {
            try {
                $this->zapiClient->sendText($phone, 'Nao encontramos lojas ativas para esta categoria no momento.');

                return true;
            } catch (\Throwable $exception) {
                Log::warning('Failed to send empty stores-by-category response.', [
                    'phone' => $phone,
                    'error' => $exception->getMessage(),
                ]);

                return false;
            }
        }

        $cards = [];

        foreach ($stores as $store) {
            $cards[] = [
                'text' => $this->carouselBuilder->formatStoreCardText($store),
                'image' => $store->logo_url
                    ?? $store->cover_image_url
                    ?? 'https://picsum.photos/seed/loja-'.$store->id.'/600/600',
                'buttons' => [
                    [
                        'id' => 'view_menu_'.$store->slug,
                        'label' => '📖 Ver Cardápio',
                        'type' => 'REPLY',
                    ],
                ],
            ];
        }

        if ($appendMoreCard) {
            $cards[] = [
                'text' => 'Ver mais lojas',
                'image' => (string) config('services.zapi.flow_more_image', 'https://picsum.photos/seed/mais-lojas/600/600'),
                'buttons' => [
                    [
                        'id' => 'flow_back_stores',
                        'label' => 'Ver mais lojas',
                        'type' => 'REPLY',
                    ],
                ],
            ];
        }

        try {
            $this->zapiClient->sendCarousel($phone, $message, $cards);

            return true;
        } catch (\Throwable $exception) {
            Log::warning('Failed to send stores carousel by category.', [
                'phone' => $phone,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }


    private function resolveCategorySlugFromButtonSlug(string $slug): ?string
    {
        $normalized = strtolower(trim($slug));

        if ($normalized === '') {
            return null;
        }

        $direct = Category::query()
            ->where('is_active', true)
            ->whereNull('store_id')
            ->where('slug', $normalized)
            ->first();

        if ($direct !== null) {
            return (string) $direct->slug;
        }

        $prefixed = Category::query()
            ->where('is_active', true)
            ->whereNull('store_id')
            ->where('slug', 'cat_'.$normalized)
            ->first();

        return $prefixed?->slug;
    }

    private function sendCategoryList(string $phone): bool
    {
        return $this->sendCategoriesCarousel($phone);
    }

    private function sendAllStoresFallback(string $phone, string $notice): bool
    {
        $stores = Store::query()
            ->where('is_active', true)
            ->with(['company:id,is_open', 'category:id,slug,name'])
            ->withPromotionFlag()
            ->orderBy('name')
            ->get()
            ->filter(fn (Store $store): bool => $store->isOpenNow())
            ->take(10)
            ->values();

        try {
            $this->zapiClient->sendText($phone, $notice);
        } catch (\Throwable $exception) {
            Log::warning('Failed to send category fallback notice.', [
                'phone' => $phone,
                'error' => $exception->getMessage(),
            ]);
        }

        return $this->sendStoreCarouselFromCollection($phone, $stores, '🏪 Lojas disponíveis — toque em Ver Cardápio para explorar');
    }
}
