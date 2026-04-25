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
    public function sendStoresPage(string $phone, int $offset = 0, ?string $customTitle = null): bool
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
        $title = $customTitle ?? '🏪 Confira nossas lojas ativas:';
        try {
            $this->zapiClient->sendCarousel($phone, $title, $cards);
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
        // 1. Busca a loja com contagem de categorias para decidir o fluxo
        $store = Store::query()
            ->where('is_active', true)
            ->where('slug', $storeSlug)
            ->withCount(['categories' => function ($query) {
                $query->where('is_active', true); // Opcional: filtrar só categorias ativas
            }])
            ->first();

        if (!$store) {
            return false;
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
        $store = Store::query()->where('slug', $storeSlug)->with('categories')->first();

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
                        'label' => '📂 Ver produtos',
                        'type' => 'REPLY',
                    ],
                ],
            ];
        }

        try {
            $this->zapiClient->sendCarousel($phone, '🍟 *Escolha uma categoria:*', $cards);
            return true;
        } catch (\Throwable $exception) {
            Log::warning('Failed to send category carousel.', ['error' => $exception->getMessage()]);
            return false;
        }
    }
    public function sendProductsByCategoryCarousel(string $phone, string $storeSlug, string $categorySlug, int $offset = 0): bool
    {
        $store = Store::query()->where('slug', $storeSlug)->first();
        if (!$store) {
            $this->zapiClient->sendText($phone, 'Loja não encontrada.');
            return false;
        }

        $category = $store->categories()->where('slug', $categorySlug)->first();
        if (!$category) {
            $this->zapiClient->sendText($phone, 'Categoria não encontrada.');
            return false;
        }

        // Busca produtos ativos da categoria na loja
        $productsQuery = $category->products()
            ->where('store_id', $store->id)
            ->where('is_active', true)
            ->orderBy('name');

        $total = $productsQuery->count();
        $products = $productsQuery->skip($offset)->take(self::STORE_PAGE_SIZE)->get();

        if ($products->isEmpty()) {
            $this->zapiClient->sendText($phone, 'Não há produtos nesta categoria.');
            return true;
        }

        $cards = [];
        foreach ($products as $product) {
            $cards[] = [
                'text' => $product->name,
                'image' => $product->image_url ?? 'https://picsum.photos/seed/'.$product->slug.'/600/600',
                'buttons' => [
                    [
                        'id' => 'view_product_'.$product->slug,
                        'label' => 'Ver detalhes',
                        'type' => 'REPLY',
                    ],
                ],
            ];
        }

        // Paginação: se houver mais produtos, adiciona card de "Ver mais"
        if ($offset + self::STORE_PAGE_SIZE < $total) {
            $cards[] = [
                'text' => 'Ver mais produtos',
                'image' => 'https://picsum.photos/seed/mais-produtos/600/600',
                'buttons' => [
                    [
                        'id' => 'view_more_products_'.$storeSlug.'_'.$categorySlug.'_'.($offset + self::STORE_PAGE_SIZE),
                        'label' => 'Ver mais',
                        'type' => 'REPLY',
                    ],
                ],
            ];
        }

        try {
            $this->zapiClient->sendCarousel($phone, 'Produtos da categoria: '.$category->name, $cards);
            return true;
        } catch (\Throwable $exception) {
            Log::warning('Failed to send products carousel.', ['error' => $exception->getMessage()]);
            return false;
        }
    }
}
