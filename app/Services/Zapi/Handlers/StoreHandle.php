<?php

namespace App\Services\Zapi\Handlers;

use App\Models\Store;
use App\Services\Zapi\Builders\StoreCarouselBuilder;
use App\Services\Zapi\Flows\FlowManager;
use App\Services\Zapi\Support\StoreSearch;
use App\Services\Whatsapp\WhatsAppClientInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class StoreHandle
{
    // 1. Definição da constante (Problema 1)
    private const STORE_PAGE_SIZE = 9;

    public function __construct(
        private FlowManager $flow,
        private StoreSearch $search,
        private WhatsAppClientInterface $zapiClient,
        private StoreCarouselBuilder $carouselBuilder
    ) {
    }


    private function saveFlowState(string $phone, array $state): void
    {
        $this->flow->saveState($phone, $state);
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
            // 1. Limpa o filtro de busca do usuário no State
            $state = $this->flow->getState($phone);
            $state['last_search']   = null;
            $state['store_results'] = null;
            $state['store_offset']  = 0;
            $this->saveFlowState($phone, $state);

            // 2. Define o texto de fallback
            $mensagemEmpatica = "Poxa, não encontrei nenhuma loja com esse nome por aqui. 😕\n\nMas não passe vontade! Dê uma olhada nessas outras opções incríveis que separei para você: 👇";

            // 3. Manda o carrossel usando o texto acima como título!
            return $this->sendStoresPage($phone, 0, $mensagemEmpatica);
        }

        Log::info('Search found stores', ['query' => $query, 'storeIds' => $storeIds]);

        // Se encontrou a loja, segue o fluxo normal com o filtro:
        $state = $this->flow->getState($phone);
        $state['last_search']   = $query;
        $state['store_results'] = $storeIds;
        $state['store_offset']  = 0;
        $this->saveFlowState($phone, $state);

        return $this->sendStoresPage($phone, 0);
    }

    private function searchStoreIds(string $query): array
    {
        return $this->search->byQuery($query);
    }

    public function sendStoresPage(string $phone, int $offset = 0, ?string $customTitle = null): bool
    {
        $state = $this->flow->getState($phone);
        $storeIds = array_values(array_filter($state['store_results'] ?? []));

        if ($storeIds === []) {
            $storeIds = Store::query()
                ->visibleOnWhatsapp()
                ->with('company:id,is_open')
                ->orderBy('name')
                ->get()
                ->filter(fn (Store $store): bool => $store->isOpenNow())
                ->pluck('slug')
                ->all();
        }

        // Usando a constante definida (Problema 1)
        $pageStoreIds = array_slice($storeIds, $offset, self::STORE_PAGE_SIZE);

        if (empty($pageStoreIds)) {
            return false;
        }

        $stores = Store::query()
            ->visibleOnWhatsapp()
            ->whereIn('slug', $pageStoreIds)
            ->with('category:id,slug,name')
            ->withPromotionFlag()
            ->get()
            ->keyBy('slug');

        $orderedStores = collect($pageStoreIds)
            ->map(fn (string $slug) => $stores->get($slug))
            ->filter();

        $cards = $this->carouselBuilder->buildStoreCards($orderedStores);

        $nextOffset = $offset + count($pageStoreIds);
        if ($nextOffset < count($storeIds)) {
            $cards[] = $this->carouselBuilder->buildMoreStoresCard($nextOffset);
        }

        $state['store_offset'] = $offset;
        $this->saveFlowState($phone, $state);
        $title = $customTitle ?? '🏪 Lojas abertas agora:';
        try {
            $this->zapiClient->sendCarousel($phone, $title, $cards);
            return true;
        } catch (\Throwable $exception) {
            Log::warning('Failed to send store carousel.', ['error' => $exception->getMessage()]);
            return false;
        }
    }

    public function selectStore(string $phone, string $storeSlug): bool
    {
        // 1. Busca a loja com contagem de categorias para decidir o fluxo
        $store = Store::query()
            ->visibleOnWhatsapp()
            ->where('slug', $storeSlug)
            ->with('company:id,is_open')
            ->withCount(['categories' => function ($query) {
                $query->where('is_active', true); // Opcional: filtrar só categorias ativas
            }])
            ->first();

        if (!$store || !$store->isOpenNow()) {
            return $this->sendStoresPage($phone, 0);
        }

        // 2. Salva a loja selecionada no estado
        $state = $this->flow->getState($phone);
        $state['selected_store_id'] = $store->slug;
        $this->saveFlowState($phone, $state);

        // 🎯 A REGRA DE OURO:
        // Se a loja tem categorias, mostra o carrossel de categorias.
        // Se NÃO tem categorias, pula direto para o carrossel geral de produtos da loja.
        if ($store->categories_count > 0) {
            return $this->sendCategoriesCarousel($phone, $store->slug);
        }

        Log::info("Loja {$store->slug} sem categorias. Pulando para produtos.");
        return $this->sendProductsCarousel($phone, $store->slug, 0);
    }

    public function sendCategoriesCarousel(string $phone, string $storeSlug): bool
    {
        // Carrega a loja com as categorias
        $store = Store::query()->where('slug', $storeSlug)->with(['categories', 'category:id,slug'])->first();

        // Verificação de segurança caso as categorias tenham sumido entre o clique e o processamento
        if (!$store || $store->categories->isEmpty()) {
            return $this->sendProductsCarousel($phone, $storeSlug, 0);
        }

        $cards = [];
        foreach ($store->categories as $category) {
            $cards[] = [
                'text' => $category->name,
                'image' => $category->image_url ?? 'https://picsum.photos/seed/'.$category->slug.'/600/600',
                'buttons' => [
                    [
                        'id' => 'view_category_'.$category->slug,
                        'label' => '🍽️ Ver produtos',
                        'type' => 'REPLY',
                    ],
                ],
            ];
        }

        // Título com o emoji do nicho da loja (§2.2: "🍕 Escolha uma categoria:"). Nome da loja
        // vai na linha de cima pra deixar claro em qual cardápio o cliente está navegando.
        $emoji = $this->carouselBuilder->getCategoryEmoji($store->category?->slug);

        try {
            $this->zapiClient->sendCarousel($phone, "🏪 *{$store->name}*\n{$emoji} Escolha uma categoria:", $cards);
            return true;
        } catch (\Throwable $exception) {
            Log::warning('Failed to send category carousel.', ['error' => $exception->getMessage()]);
            return false;
        }
    }

    /**
     * Produtos de uma categoria da loja — mesmo card/formato/paginação do carrossel geral de
     * produtos (§5), só filtrado pela categoria (delegado ao ProductsHandler).
     */
    public function sendProductsByCategoryCarousel(string $phone, string $storeSlug, string $categorySlug, int $offset = 0): bool
    {
        return app(\App\Services\Zapi\Handlers\ProductsHandler::class)
            ->sendProductsCarousel($phone, $storeSlug, $offset, $categorySlug);
    }
}
