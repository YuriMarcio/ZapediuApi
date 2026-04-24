<?php

namespace App\Services\Zapi\Handlers;

use App\Models\Store;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Services\Zapi\Flows\FlowManager;
use App\Services\Zapi\Support\StoreSearch;
use App\Services\Zapi\ZapiClient;
use App\Services\Zapi\Builders\ProductCarouselBuilder; // Injetando o Builder
use Illuminate\Support\Facades\Log;

class ProductsHandler
{
    // 2. Definição da constante (Problema 2)
    private const PRODUCT_PAGE_SIZE = 9;

    public function __construct(
        private FlowManager $flow,
        private StoreSearch $search,
        private ZapiClient $zapiClient,
        private ProductCarouselBuilder $carouselBuilder // Injeção do Builder
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
                'buttons' => [
                    ['id' => 'flow_add1_'.$store->slug.'_'.(int) $product->id, 'label' => '➕ Adicionar 1', 'type' => 'REPLY'],
                    ['id' => 'flow_add2_'.$store->slug.'_'.(int) $product->id, 'label' => '➕ Adicionar 2', 'type' => 'REPLY'],
                    ['id' => 'flow_add3_'.$store->slug.'_'.(int) $product->id, 'label' => '➕ Adicionar 3', 'type' => 'REPLY'],
                ],
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


    public function sendProductResponse(string $phone): bool
    {
        $catalogPhone = trim((string) config('services.zapi.catalog_phone'));
        $productId = trim((string) config('services.zapi.product_id'));

        if ($catalogPhone === '' || $productId === '') {
            return false;
        }

        try {
            $this->zapiClient->sendProduct($phone, $catalogPhone, $productId);
            return true;
        } catch (\Throwable $exception) {
            Log::warning('Failed to send Z-API product response.', [
                'phone' => $phone,
                'error' => $exception->getMessage(),
            ]);
            return false;
        }
    }

    private function buildMenuIntroMessage(Store $store): string
    {
        return '📖 Cardápio: '.$store->name." 📖\n\n"
            .'Deslize para o lado, escolha o seu pedido e clique em Adicionar'
            ."\n";
    }

}
