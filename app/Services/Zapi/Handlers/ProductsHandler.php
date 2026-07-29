<?php

namespace App\Services\Zapi\Handlers;

use App\Models\Category;
use App\Models\Store;
use App\Models\StorePizzaSize;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Services\Pizzaria\PizzaPricingResolver;
use App\Services\Zapi\Flows\FlowManager;
use App\Services\Zapi\Support\StoreSearch;
use App\Services\Whatsapp\WhatsAppClientInterface;
use App\Services\Zapi\Builders\ProductCarouselBuilder; // Injetando o Builder
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProductsHandler
{
    // 2. Definição da constante (Problema 2)
    private const PRODUCT_PAGE_SIZE = 9;

    public function __construct(
        private FlowManager $flow,
        private StoreSearch $search,
        private WhatsAppClientInterface $zapiClient,
        private ProductCarouselBuilder $carouselBuilder, // Injeção do Builder
        private PizzaPricingResolver $pizzaPricing
    ) {
    }

    /**
     * Carrossel de produtos da loja — todos, ou filtrado por categoria da loja quando
     * $categorySlug vem preenchido. Paginação 9+1 (§4 do documento).
     */
    public function sendProductsCarousel(string $phone, string $storeId, int $offset, ?string $categorySlug = null): bool
    {
        $store = Store::query()
            ->with('category')
            ->visibleOnWhatsapp()
            ->where('slug', $storeId)
            ->first();

        if ($store === null) {
            try {
                $this->zapiClient->sendText(
                    $phone,
                    "Esta loja não está disponível no momento.\n\nEscolha outra loja para continuar seu pedido."
                );
            } catch (\Throwable $exception) {
                Log::warning('Failed to send store unavailable notice.', [
                    'phone' => $phone,
                    'store_slug' => $storeId,
                    'error' => $exception->getMessage(),
                ]);
            }

            return true;
        }

        $category = null;
        if ($categorySlug !== null) {
            $category = $store->categories()->where('slug', $categorySlug)->first();
            if ($category === null) {
                $this->zapiClient->sendText($phone, 'Categoria não encontrada.');

                return false;
            }
        }

        $productsQuery = Product::query()
            ->with('variations')
            ->available()
            ->where('store_id', $store->id)
            ->when($category !== null, fn ($q) => $q->where('category_id', $category->id))
            ->orderBy('name');

        $totalProducts = (clone $productsQuery)->count();
        $pageProducts = $productsQuery
            ->skip($offset)
            ->take(self::PRODUCT_PAGE_SIZE)
            ->get();

        if ($pageProducts->isEmpty()) {
            if ($category !== null) {
                $this->zapiClient->sendText($phone, 'Não há produtos nesta categoria.');

                return true;
            }

            return false;
        }

        $cards = [];

        foreach ($pageProducts as $product) {
            $cards[] = $this->buildProductCard($store, $product);
        }

        $nextOffset = $offset + count($pageProducts);
        $hasMorePages = $nextOffset < $totalProducts;

        // §4: 9 itens reais + 1 card "Ver mais" = 10 (limite do WhatsApp). Última página sem
        // itens suficientes não mostra o "Ver mais" — entra o card de voltar às lojas no lugar.
        if ($hasMorePages) {
            $moreId = $category !== null
                ? 'flow_catprod_more_'.$store->slug.'_'.$category->slug.'_'.$nextOffset
                : 'flow_product_more_'.$store->slug.'_'.$nextOffset;

            $cards[] = [
                'text' => 'Ver mais produtos',
                'image' => (string) config('services.zapi.flow_more_image', 'https://picsum.photos/seed/mais-lojas/600/600'),
                'buttons' => [
                    ['id' => $moreId, 'label' => 'Ver mais', 'type' => 'REPLY'],
                ],
            ];
        } else {
            $cards[] = [
                'text' => 'Quer escolher outra loja?',
                'image' => (string) config('services.zapi.flow_back_to_stores_image', 'https://picsum.photos/seed/outras-lojas/600/600'),
                'buttons' => [
                    ['id' => 'flow_back_stores', 'label' => 'Voltar lojas', 'type' => 'REPLY'],
                ],
            ];
        }

        try {
            $introMessage = $category !== null
                ? "🏪 *{$store->name}*\nProdutos da categoria: {$category->name}"
                : $this->carouselBuilder->buildMenuIntroMessage($store);

            $response = $this->zapiClient->sendCarousel($phone, $introMessage, $cards);

            if (isset($response['messageId'])) {
                $state = $this->flow->getState($phone);
                $state['last_product_menu_id'] = $response['messageId'];
                $this->flow->saveState($phone, $state);
            }

            return true;
        } catch (\Throwable $exception) {
            Log::warning('Failed to send products carousel.', [
                'error' => $exception->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Card de produto padrão do documento (§5): texto formatado + até 2 botões
     * ("🛒 Adicionar por R$ X" e "🔢 Quero mais de um"). Lojas pizzaria/açaiteria com
     * produto de tamanho continuam com os botões de tamanho no card (§7 Passo 2).
     */
    public function buildProductCard(Store $store, Product $product, bool $showStoreName = false): array
    {
        return [
            'text' => $this->carouselBuilder->formatProductCardText($product, $store, $showStoreName),
            'image' => $product->image_path ?? 'https://picsum.photos/seed/produto-'.(int) $product->id.'/600/600',
            'buttons' => $this->buildProductButtons($store, $product),
        ];
    }

    /**
     * §1.1.b — busca por produto via IA: carrossel cruzando várias lojas (fuzzy match no
     * nome/descrição do produto), cada card com o nome da loja na descrição e o mesmo botão
     * "🛒 Adicionar por R$ X" do carrossel normal (§5) — o id do botão já carrega a loja do
     * próprio produto, então mono-loja (§10) e customização (§6) seguem funcionando sem
     * mudança nenhuma no CartFlow.
     *
     * Marca cada produto exibido com um flag transitório (Cache) pro CartFlow saber, na hora
     * de comitar, que essa adição veio da busca por IA — é o que decide a confirmação de 3
     * botões (com "📖 Cardápio da loja") em vez da padrão de 2.
     */
    public function sendProductSearchCarousel(string $phone, string $term, int $offset = 0): bool
    {
        $products = $this->search->productsByQuery($term);

        if ($products->isEmpty()) {
            return false;
        }

        $total = $products->count();
        $pageProducts = $products->slice($offset, self::PRODUCT_PAGE_SIZE)->values();

        if ($pageProducts->isEmpty()) {
            return false;
        }

        $cards = [];
        foreach ($pageProducts as $product) {
            $store = $product->store;
            $cards[] = $this->buildProductCard($store, $product, showStoreName: true);
            Cache::put('zapi:ai_search_flag:'.$phone.':'.$store->slug.':'.$product->id, true, 900);
        }

        $nextOffset = $offset + $pageProducts->count();
        if ($nextOffset < $total) {
            $cards[] = [
                'text' => 'Ver mais produtos',
                'image' => (string) config('services.zapi.flow_more_image', 'https://picsum.photos/seed/mais-lojas/600/600'),
                'buttons' => [
                    ['id' => 'flow_prodsearch_more_'.$nextOffset, 'label' => 'Ver mais', 'type' => 'REPLY'],
                ],
            ];
        }

        $state = $this->flow->getState($phone);
        $state['product_search_term'] = $term;
        $this->flow->saveState($phone, $state);

        try {
            $intro = "🔎 Show! Encontrei esses produtos pra \"{$term}\":";
            $this->zapiClient->sendCarousel($phone, $intro, $cards);

            return true;
        } catch (\Throwable $exception) {
            Log::warning('Failed to send product search carousel.', ['error' => $exception->getMessage()]);

            return false;
        }
    }

    /**
     * §1.1.b nível 2 — quando o termo não bate em título/descrição (ex: "hambúrguer", que não
     * aparece no nome de nenhum produto): a IA já classificou numa categoria GERAL/segmento
     * (`$generalCategory`, ex: "HAMBURGUERIA") e `StoreSearch::productsByGeneralCategoryAndTerm`
     * já cruzou isso com o termo original (`$item`) contra a categoria própria de cada loja.
     * Mesmo padrão de carrossel/paginação/flag de "veio da busca" do nível 1.
     */
    public function sendProductSearchByCategoryCarousel(string $phone, string $generalCategory, string $item, int $offset = 0): bool
    {
        $products = $this->search->productsByGeneralCategoryAndTerm($generalCategory, $item);

        if ($products->isEmpty()) {
            return false;
        }

        $total = $products->count();
        $pageProducts = $products->slice($offset, self::PRODUCT_PAGE_SIZE)->values();

        if ($pageProducts->isEmpty()) {
            return false;
        }

        $cards = [];
        foreach ($pageProducts as $product) {
            $store = $product->store;
            $cards[] = $this->buildProductCard($store, $product, showStoreName: true);
            Cache::put('zapi:ai_search_flag:'.$phone.':'.$store->slug.':'.$product->id, true, 900);
        }

        $nextOffset = $offset + $pageProducts->count();
        if ($nextOffset < $total) {
            $cards[] = [
                'text' => 'Ver mais produtos',
                'image' => (string) config('services.zapi.flow_more_image', 'https://picsum.photos/seed/mais-lojas/600/600'),
                'buttons' => [
                    ['id' => 'flow_prodsearch_cat_more_'.$nextOffset, 'label' => 'Ver mais', 'type' => 'REPLY'],
                ],
            ];
        }

        $state = $this->flow->getState($phone);
        $state['category_search_general'] = $generalCategory;
        $state['category_search_item'] = $item;
        $this->flow->saveState($phone, $state);

        try {
            $intro = "😔 Não achei um produto chamado \"{$item}\", mas separei essas opções parecidas pra você:";
            $this->zapiClient->sendCarousel($phone, $intro, $cards);

            return true;
        } catch (\Throwable $exception) {
            Log::warning('Failed to send category search carousel.', ['error' => $exception->getMessage()]);

            return false;
        }
    }

    /**
     * Lojas 'pizzaria'/'acaiteria' mostram botões de tamanho (leva direto pro fluxo de
     * sabor+borda em CartFlow::handlePizzaSizePicked) quando o produto tem variações ativas.
     * Qualquer outro caso segue o padrão do documento (§5): adicionar 1 direto ou abrir o
     * seletor de quantidade.
     */
    private function buildProductButtons(Store $store, Product $product): array
    {
        if ($store->isFlavorMenuStore() && (bool) $product->has_variations) {
            $variations = $product->variations
                ->filter(fn (ProductVariation $v): bool => (bool) $v->is_active)
                ->values();

            if ($variations->isNotEmpty()) {
                // Lojas pizzaria (motor avançado): preço do botão segue a hierarquia
                // categoria → exceção do sabor, não mais só additional_price da variação.
                if ($store->isPizzaAdvancedStore()) {
                    $sizesById = StorePizzaSize::query()
                        ->whereIn('id', $variations->pluck('store_pizza_size_id')->filter())
                        ->get()
                        ->keyBy('id');

                    return $variations->take(3)->map(function (ProductVariation $v) use ($product, $sizesById): array {
                        $size = $v->store_pizza_size_id !== null ? $sizesById->get($v->store_pizza_size_id) : null;
                        $price = $size !== null ? $this->pizzaPricing->resolveFlavorPrice($product, $size)['price'] : null;

                        return [
                            'id'    => 'flow_pizza_size_'.(int) $product->id.'_'.(int) $v->id,
                            'label' => $v->name.($price !== null ? ' — R$ '.number_format($price, 2, ',', '.') : ''),
                            'type'  => 'REPLY',
                        ];
                    })->values()->all();
                }

                return $variations->take(3)->map(fn (ProductVariation $v): array => [
                    'id'    => 'flow_pizza_size_'.(int) $product->id.'_'.(int) $v->id,
                    'label' => $v->name.' — R$ '.number_format(((float) $product->price) + ((float) $v->additional_price), 2, ',', '.'),
                    'type'  => 'REPLY',
                ])->values()->all();
            }
        }

        $price = $this->carouselBuilder->effectivePrice($product);

        return [
            [
                'id' => 'flow_add1_'.$store->slug.'_'.(int) $product->id,
                'label' => '🛒 Adicionar por R$ '.number_format($price, 2, ',', '.'),
                'type' => 'REPLY',
            ],
            [
                'id' => 'flow_qty_'.$store->slug.'_'.(int) $product->id,
                'label' => '🔢 Quero mais de um',
                'type' => 'REPLY',
            ],
        ];
    }

    /**
     * §5.2 — "Quero mais de um": lista interativa 2-9 + "10+" (única exceção que pede texto).
     */
    public function sendQuantityPicker(string $phone, string $storeSlug, int $productId): bool
    {
        $product = Product::query()->available()->find($productId);

        if ($product === null) {
            return false;
        }

        $rows = [];
        foreach (range(2, 9) as $qty) {
            $rows[] = [
                'id' => 'flow_qtypick_'.$storeSlug.'_'.$productId.'_'.$qty,
                'title' => (string) $qty,
                'description' => '',
            ];
        }
        $rows[] = [
            'id' => 'flow_qty10_'.$storeSlug.'_'.$productId,
            'title' => '10+',
            'description' => 'Digitar a quantidade',
        ];

        try {
            $this->zapiClient->sendList(
                $phone,
                'Quantas unidades de "'.$product->name.'" você quer?',
                'Escolher quantidade',
                $product->name,
                '',
                $rows
            );

            return true;
        } catch (\Throwable $exception) {
            Log::warning('Failed to send quantity picker.', ['error' => $exception->getMessage()]);

            return false;
        }
    }
}
