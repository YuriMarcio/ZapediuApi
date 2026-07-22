<?php

namespace App\Services\Zapi\Handlers;

use App\Models\Store;
use App\Models\StorePizzaSize;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Services\Pizzaria\PizzaPricingResolver;
use App\Services\Zapi\Flows\FlowManager;
use App\Services\Zapi\Support\StoreSearch;
use App\Services\Whatsapp\WhatsAppClientInterface;
use App\Services\Zapi\Builders\ProductCarouselBuilder; // Injetando o Builder
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

    public function sendProductsCarousel(string $phone, string $storeId, int $offset): bool
    {
        $store = Store::query()
            ->with('category')
            ->where('is_active', true)
            ->where('slug', $storeId)
            ->first();

        if ($store === null) {
            return false;
        }

        $productsQuery = Product::query()
            ->with('variations')
            ->where('is_active', true)
            ->where('store_id', $store->id)
            ->orderBy('name');

        $totalProducts = (clone $productsQuery)->count();
        $pageProducts = $productsQuery
            ->skip($offset)
            ->take(self::PRODUCT_PAGE_SIZE)
            ->get();

        if ($pageProducts->isEmpty()) {
            return false;
        }

        $cards = [];

        foreach ($pageProducts as $product) {
            $cards[] = [
                // Chamando o método public do Builder (Problema 3)
                'text' => $this->carouselBuilder->formatProductCardText($product, $store),
                'image' => $product->image_path ?? 'https://picsum.photos/seed/produto-'.(int) $product->id.'/600/600',
                'buttons' => $this->buildProductButtons($store, $product),
            ];
        }

        $nextOffset = $offset + count($pageProducts);

        // Card de "Mostrar Mais"
        if ($nextOffset < $totalProducts) {
            $cards[] = [
                'text' => 'Mostrar mais produtos da loja',
                'image' => (string) config('services.zapi.flow_more_image', 'https://picsum.photos/seed/mais-lojas/600/600'),
                'buttons' => [
                    [
                        'id' => 'flow_product_more_'.$store->slug.'_'.$nextOffset,
                        'label' => 'Mostrar mais',
                        'type' => 'REPLY',
                    ],
                ],
            ];
        }

        // Card de Retorno
        $cards[] = [
            'text' => 'Quer escolher outra loja?',
            'image' => (string) config('services.zapi.flow_back_to_stores_image', 'https://picsum.photos/seed/outras-lojas/600/600'),
            'buttons' => [
                [
                    'id' => 'flow_back_stores',
                    'label' => 'Voltar lojas',
                    'type' => 'REPLY',
                ],
            ],
        ];

        try {
            $introMessage = $this->carouselBuilder->buildMenuIntroMessage($store);

            $response = $this->zapiClient->sendCarousel($phone, $introMessage, $cards);

            if (isset($response['messageId'])) {
                $state = $this->flow->getState($phone);
                $state['last_product_menu_id'] = $response['messageId'];
                $this->flow->saveState($phone, $state);

                // ✅ Log de Sucesso: Confirma que o ID foi pego e salvo
                \Illuminate\Support\Facades\Log::info('Menu messageId salvo com sucesso', [
                    'phone' => $phone,
                    'messageId' => $response['messageId']
                ]);
            } else {
                // ⚠️ Log de Alerta: Se cair aqui, a Z-API mudou a resposta ou deu algum erro silencioso
                \Illuminate\Support\Facades\Log::warning('Z-API não retornou messageId no envio do carrossel', [
                    'phone' => $phone,
                    'response' => $response
                ]);
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
     * Lojas 'pizzaria'/'acaiteria' mostram botões de tamanho (leva direto pro fluxo de
     * sabor+borda em CartFlow::handlePizzaSizePicked) quando o produto tem variações ativas.
     * Qualquer outro caso (loja padrão, ou produto sem tamanho) mantém os botões de
     * quantidade de sempre — nada muda pra quem já usa o fluxo antigo.
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
                    'label' => $v->name.(
                        ((float) $v->additional_price) > 0
                            ? ' — R$ '.number_format(((float) $product->price) + ((float) $v->additional_price), 2, ',', '.')
                            : ' — R$ '.number_format((float) $product->price, 2, ',', '.')
                    ),
                    'type'  => 'REPLY',
                ])->values()->all();
            }
        }

        return [
            ['id' => 'flow_add1_'.$store->slug.'_'.(int) $product->id, 'label' => '➕ Adicionar 1', 'type' => 'REPLY'],
            ['id' => 'flow_add2_'.$store->slug.'_'.(int) $product->id, 'label' => '➕ Adicionar 2', 'type' => 'REPLY'],
            ['id' => 'flow_add3_'.$store->slug.'_'.(int) $product->id, 'label' => '➕ Adicionar 3', 'type' => 'REPLY'],
        ];
    }

    private function buildMenuIntroMessage(Store $store): string
    {
        return '📖 Cardápio: '.$store->name." 📖\n\n"
            .'Deslize para o lado, escolha o seu pedido e clique em Adicionar'
            ."\n";
    }

}
