<?php

namespace App\Services\Zapi\Handlers;

use App\Models\Category;
use App\Models\Store;
use App\Services\Zapi\Flows\FlowManager;
use App\Services\Zapi\Support\StoreSearch;
use App\Services\Zapi\ZapiClient;
use Illuminate\Support\Facades\Log;

class CategoriesHandle
{
    public function __construct(
        private FlowManager $flow,
        private StoreSearch $search,
        private ZapiClient $zapiClient,
        // Injetando StoreHandle para resolver o problema 4
        private StoreHandle $storeHandle
    ) {
    }

    /**
     * Auxiliar para salvar estado (Problema 2)
     */
    private function saveFlowState(string $phone, array $state): void
    {
        $this->flow->saveState($phone, $state);
    }

    private function buildCategoryHeader(Category $category): string
    {
        return "📂 *Categoria: {$category->name}*\n\nConfira as lojas disponíveis abaixo:";
    }

    private function buttonSlugFromCategorySlug(string $slug): string
    {
        // Garante que o slug não tenha o prefixo duplicado para o ID do botão
        return str_replace('cat_', '', $slug);
    }

    private function formatStoreCardText(Store $store): string
    {
        // Stub de formatação para o card da loja (reutilizando padrão do sistema)
        $rating = "⭐ 4.8"; // Em prod: $store->rating
        return "🏪 *{$store->name}*\n{$rating} • 🛵 30-45 min";
    }
    /**
       * Resolve o problema 4: Delega para o StoreHandle que possui a lógica de paginação
       */
    public function returnToStores(string $phone): bool
    {
        $state = $this->flow->getState($phone);
        $state['selected_store_id'] = null;
        $state['store_offset'] = 0;
        $this->saveFlowState($phone, $state);

        return $this->storeHandle->sendStoresPage($phone, 0);
    }

   public function sendCategoryStores(string $phone, string $categoryId): bool
    {
        $category = Category::query()
            ->where('is_active', true)
            ->where('slug', $categoryId)
            ->first();

        if ($category === null) {
            return false;
        }

        $limit = 9;
        $stores = Store::query()
            ->where('is_active', true)
            ->where('category_id', $category->id)
            ->orderBy('name')
            ->limit($limit)
            ->get();

        return $this->sendStoreCarouselFromCollection(
            $phone, 
            $stores, 
            $this->buildCategoryHeader($category), 
            true
        );
    }

    public function sendCategoriesCarousel(string $phone): bool
    {
        $categories = Category::query()
            ->where('is_active', true)
            ->orderBy('ordem_exibicao')
            ->limit(10)
            ->get();

        if ($categories->isEmpty()) {
            return $this->sendAllStoresFallback($phone, 'Nenhuma categoria encontrada no momento.');
        }

        $cards = [];
        foreach ($categories as $category) {
            $cards[] = [
                'text' => "📂 " . $category->name,
                'image' => $category->image_url ?: 'https://picsum.photos/seed/cat-'.$category->id.'/600/600',
                'buttons' => [
                    [
                        'id' => 'buscar_cat_' . $this->buttonSlugFromCategorySlug((string) $category->slug),
                        'label' => 'Ver lojas',
                        'type' => 'REPLY',
                    ],
                ],
            ];
        }

        try {
            $this->zapiClient->sendCarousel($phone, 'Escolha uma categoria para ver as lojas:', $cards);
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
                'text' => $this->formatStoreCardText($store),
                'image' => $store->cover_image_url
                    ?? $store->logo_url
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
            ->where('slug', $normalized)
            ->first();

        if ($direct !== null) {
            return (string) $direct->slug;
        }

        $prefixed = Category::query()
            ->where('is_active', true)
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
            ->orderBy('name')
            ->limit(10)
            ->get();

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
