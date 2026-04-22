<?php

namespace App\Services\Zapi\Handlers;

use App\Models\Store;
use App\Services\Zapi\Builders\StoreCarouselBuilder;
use App\Services\Zapi\Flows\FlowManager;
use App\Services\Zapi\Support\StoreSearch;
use App\Services\Zapi\ZapiClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class StoreHandle
{
    // 1. Definição da constante (Problema 1)
    private const STORE_PAGE_SIZE = 9;

    public function __construct(
        private FlowManager $flow,
        private StoreSearch $search,
        private ZapiClient $zapiClient,
        private StoreCarouselBuilder $carouselBuilder
    ) {
    }


    private function saveFlowState(string $phone, array $state): void
    {
        $this->flow->saveState($phone, $state);
    }

    /**
     * Problema 3: Delegando a formatação para o Builder
     */
    private function formatStoreCardText(Store $store): string
    {
        // Agora usamos o método público do Builder injetado
        return $this->carouselBuilder->formatStoreCardText($store);
    }

    /**
     * Problema 3: Delegando a exibição de produtos para o Handler correto
     */
    public function sendProductsCarousel(string $phone, string $storeSlug, int $offset): bool
    {
        return app(\App\Services\Zapi\Handlers\ProductsHandler::class)
               ->sendProductsCarousel($phone, $storeSlug, $offset);
    }


    public function sendStoreSearchResults(string $phone, string $query): bool
    {
        $storeIds = $this->searchStoreIds($query);

        if ($storeIds === []) {
            try {
                $this->zapiClient->sendText(
                    $phone,
                    'Não encontrei lojas para essa busca. Tente outro termo ou digite *filtro*.'
                );
                return true;
            } catch (\Throwable $exception) {
                Log::warning('Failed to send empty-search response.', ['error' => $exception->getMessage()]);
                return false;
            }
        }

        $state = $this->flow->getState($phone);
        $state['last_search'] = $query;
        $state['store_results'] = $storeIds;
        $state['store_offset'] = 0;
        $this->saveFlowState($phone, $state);

        return $this->sendStoresPage($phone, 0);
    }

    private function searchStoreIds(string $query): array
    {
        $normalizedQuery = trim((string) Str::of($query)->lower()->ascii()->toString());

        $storesQuery = Store::query()
            ->where('is_active', true)
            ->with('category:id,name,slug');

        if ($normalizedQuery !== '') {
            $tokens = array_values(array_filter(explode(' ', $normalizedQuery)));

            foreach ($tokens as $token) {
                $storesQuery->where(function ($builder) use ($token): void {
                    $like = '%'.$token.'%';

                    $builder
                        ->whereRaw('LOWER(name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(slug) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(description) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(segment) LIKE ?', [$like])
                        ->orWhereHas('category', fn ($categoryBuilder) => $categoryBuilder->whereRaw('LOWER(name) LIKE ?', [$like]));
                });
            }
        }

        return $storesQuery
            ->orderBy('name')
            ->pluck('slug')
            ->filter(fn (mixed $slug): bool => is_string($slug) && trim($slug) !== '')
            ->values()
            ->all();
    }
    public function sendStoresPage(string $phone, int $offset): bool
    {
        $state = $this->flow->getState($phone);
        $storeIds = array_values(array_filter($state['store_results'] ?? []));

        if ($storeIds === []) {
            $storeIds = Store::query()->where('is_active', true)->orderBy('name')->pluck('slug')->toArray();
        }

        // Usando a constante definida (Problema 1)
        $pageStoreIds = array_slice($storeIds, $offset, self::STORE_PAGE_SIZE);

        if (empty($pageStoreIds)) {
            return false;
        }

        $stores = Store::query()
            ->where('is_active', true)
            ->whereIn('slug', $pageStoreIds)
            ->with('category:id,slug,name')
            ->get()
            ->keyBy('slug');

        $cards = [];
        foreach ($pageStoreIds as $storeId) {
            $store = $stores->get($storeId);
            if (!$store) {
                continue;
            }

            $cards[] = [
                'text' => $this->formatStoreCardText($store),
                'image' => $store->cover_image_path ?? $store->logo_path ?? 'https://picsum.photos/seed/'.$store->slug.'/600/600',
                'buttons' => [
                    [
                        'id' => 'view_menu_'.$store->slug,
                        'label' => '📖 Ver Cardápio',
                        'type' => 'REPLY',
                    ],
                ],
            ];
        }

        $nextOffset = $offset + count($pageStoreIds);
        if ($nextOffset < count($storeIds)) {
            $cards[] = [
                'text' => 'Ver mais lojas disponíveis',
                'image' => 'https://picsum.photos/seed/mais-lojas/600/600',
                'buttons' => [['id' => 'view_more_'.$nextOffset, 'label' => 'Ver mais', 'type' => 'REPLY']],
            ];
        }

        $state['store_offset'] = $offset;
        $this->saveFlowState($phone, $state);

        try {
            $this->zapiClient->sendCarousel($phone, '🏪 Confira nossas lojas ativas:', $cards);
            return true;
        } catch (\Throwable $exception) {
            Log::warning('Failed to send store carousel.', ['error' => $exception->getMessage()]);
            return false;
        }
    }

    private function buildStoreDeliveryFee(Store $store): float
    {
        return 8.00;
    }

    public function selectStore(string $phone, string $storeSlug): bool
    {
        $store = Store::query()->where('is_active', true)->where('slug', $storeSlug)->first();
        if (!$store) {
            return false;
        }

        $state = $this->flow->getState($phone);
        $state['selected_store_id'] = $store->slug;
        $this->saveFlowState($phone, $state);

        return $this->sendProductsCarousel($phone, (string) $store->slug, 0);
    }

}
