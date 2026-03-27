<?php

namespace App\Services\Zapi;

use App\Models\Delivery;
use App\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ZapiWebhookService
{
    private const FLOW_STATE_CACHE_PREFIX = 'zapi:flow:state:';

    private const STORE_PAGE_SIZE = 9;

    private const PRODUCT_PAGE_SIZE = 5;

    public function __construct(private readonly ZapiClient $zapiClient)
    {
    }

    public function ingest(array $payload): WebhookEvent
    {
        $event = WebhookEvent::create([
            'provider' => 'zapi',
            'event_type' => $this->eventType($payload),
            'external_id' => $this->resolveExternalId($payload),
            'payload' => $payload,
            'processed_at' => now(),
        ]);

        $deliveryAttributes = $this->extractDeliveryAttributes($payload);

        if ($deliveryAttributes !== null) {
            $lookupExternalId = $deliveryAttributes['external_id'] ?? null;
            $lookupOrderCode = $deliveryAttributes['order_code'] ?? null;

            if ($lookupExternalId !== null) {
                $delivery = Delivery::firstOrNew(['external_id' => $lookupExternalId]);
            } elseif ($lookupOrderCode !== null) {
                $delivery = Delivery::firstOrNew(['order_code' => $lookupOrderCode]);
            } else {
                $delivery = new Delivery();
            }

            $delivery->fill($deliveryAttributes);
            $delivery->save();
        }

        $this->maybeSendAutoReply($payload);

        return $event;
    }

    private function maybeSendAutoReply(array $payload): void
    {
        if (! (bool) config('services.zapi.auto_reply_enabled')) {
            return;
        }

        if ($this->isOutgoingMessage($payload)) {
            return;
        }

        $phone = $this->resolveIncomingPhone($payload);

        if ($phone === null) {
            return;
        }

        if ($this->handleCommerceFlow($payload, $phone)) {
            return;
        }

        $messageText = $this->resolveIncomingMessageText($payload);

        if ($messageText === null) {
            return;
        }

        $this->sendWelcomePrompt($phone);
    }

    private function handleCommerceFlow(array $payload, string $phone): bool
    {
        $selectedCategoryId = $this->resolveSelectedCategoryId($payload);

        if ($selectedCategoryId !== null) {
            return $this->sendCategoryStores($phone, $selectedCategoryId);
        }

        $buttonId = strtolower(trim((string) ($this->resolveButtonReplyId($payload) ?? '')));

        if ($buttonId !== '') {
            return $this->handleFlowButton($phone, $buttonId);
        }

        $messageText = $this->resolveIncomingMessageText($payload);

        if ($messageText === null) {
            return false;
        }

        return $this->handleFlowText($phone, $messageText);
    }

    private function handleFlowText(string $phone, string $messageText): bool
    {
        $normalizedText = $this->normalizeText($messageText);
        $state = $this->flowState($phone);

        if ($normalizedText === '') {
            return false;
        }

        if (($state['welcomed'] ?? false) !== true) {
            $state['welcomed'] = true;
            $this->saveFlowState($phone, $state);

            return $this->sendWelcomePrompt($phone);
        }

        if ($normalizedText === 'carrinho') {
            return $this->sendCartSummary($phone);
        }

        if (in_array($normalizedText, ['finalizar', 'checkout', 'pagar'], true)) {
            return $this->finalizeCart($phone);
        }

        if (in_array($normalizedText, ['voltar', 'voltar lojas', 'trocar loja', 'outra loja'], true)) {
            return $this->returnToStores($phone);
        }

        if (in_array($normalizedText, ['oi', 'ola', 'oie', 'menu', 'inicio', 'start'], true)) {
            return $this->sendWelcomePrompt($phone);
        }

        $triggerKeyword = $this->normalizeText((string) config('services.zapi.list_trigger_keyword', 'filtro'));
        $filterKeywords = array_values(array_filter([
            $triggerKeyword,
            'filtro',
            'filtrar',
            'categoria',
            'categorias',
        ]));

        foreach ($filterKeywords as $keyword) {
            if ($keyword !== '' && str_contains($normalizedText, $keyword)) {
                return $this->sendCategoryList($phone);
            }
        }

        if (in_array($normalizedText, ['ver lojas', 'lojas', 'mostrar lojas'], true)) {
            $allStoreIds = array_keys($this->storesCatalog());
            $state['store_results'] = $allStoreIds;
            $state['store_offset'] = 0;
            $state['selected_store_id'] = null;
            $this->saveFlowState($phone, $state);

            return $this->sendStoresPage($phone, 0);
        }

        if (str_starts_with($normalizedText, 'filtro ')) {
            return $this->sendCategoryList($phone);
        }

        return $this->sendStoreSearchResults($phone, $messageText);
    }

    private function handleFlowButton(string $phone, string $buttonId): bool
    {
        if ($buttonId === 'flow_home') {
            return $this->sendWelcomePrompt($phone);
        }

        if ($buttonId === 'flow_cart') {
            return $this->sendCartSummary($phone);
        }

        if ($buttonId === 'flow_back_stores') {
            return $this->returnToStores($phone);
        }

        if ($buttonId === 'flow_checkout') {
            return $this->finalizeCart($phone);
        }

        if (preg_match('/^flow_more_(\d+)$/', $buttonId, $matches) === 1) {
            return $this->sendStoresPage($phone, (int) $matches[1]);
        }

        if (preg_match('/^view_more_(\d+)$/', $buttonId, $matches) === 1) {
            return $this->sendStoresPage($phone, (int) $matches[1]);
        }

        if (preg_match('/^flow_store_([a-z0-9_\-]+)$/', $buttonId, $matches) === 1) {
            return $this->selectStore($phone, $matches[1]);
        }

        if (preg_match('/^view_menu_([a-z0-9_\-]+)$/', $buttonId, $matches) === 1) {
            return $this->selectStore($phone, $matches[1]);
        }

        if (preg_match('/^flow_product_more_([a-z0-9_\-]+)_(\d+)$/', $buttonId, $matches) === 1) {
            return $this->sendProductsCarousel($phone, $matches[1], (int) $matches[2]);
        }

        $addPayload = $this->resolveAddButtonPayload($buttonId);

        if ($addPayload !== null) {
            return $this->addProductToCart($phone, $addPayload['store_id'], $addPayload['product_id']);
        }

        return $this->handleCommerceReplyIntent(['buttonId' => $buttonId], $phone, $buttonId);
    }

    private function resolveAddButtonPayload(string $buttonId): ?array
    {
        if (! str_starts_with($buttonId, 'flow_add_')) {
            return null;
        }

        $rawPayload = substr($buttonId, strlen('flow_add_'));

        if ($rawPayload === false || $rawPayload === '') {
            return null;
        }

        $storeIds = array_keys($this->storesCatalog());

        usort($storeIds, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        foreach ($storeIds as $storeId) {
            $prefix = $storeId.'_';

            if (! str_starts_with($rawPayload, $prefix)) {
                continue;
            }

            $productId = substr($rawPayload, strlen($prefix));

            if ($productId === false || $productId === '') {
                return null;
            }

            return [
                'store_id' => $storeId,
                'product_id' => $productId,
            ];
        }

        return null;
    }

    private function sendWelcomePrompt(string $phone): bool
    {
        $message = trim((string) config('services.zapi.flow_welcome_message', 'Ola, digite o que procura ou digite filtro.'));

        if ($message === '') {
            return false;
        }

        $state = $this->flowState($phone);
        $state['welcomed'] = true;
        $this->saveFlowState($phone, $state);

        try {
            $this->zapiClient->sendText($phone, $message);

            return true;
        } catch (\Throwable $exception) {
            Log::warning('Failed to send Z-API welcome prompt.', [
                'phone' => $phone,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private function sendStoreSearchResults(string $phone, string $query): bool
    {
        $storeIds = $this->searchStoreIds($query);

        if ($storeIds === []) {
            try {
                $this->zapiClient->sendText(
                    $phone,
                    'Nao encontrei lojas para essa busca. Tente outro termo ou digite filtro para navegar por categorias.'
                );

                return true;
            } catch (\Throwable $exception) {
                Log::warning('Failed to send empty-search response.', [
                    'phone' => $phone,
                    'error' => $exception->getMessage(),
                ]);

                return false;
            }
        }

        $storeIds = $this->padSearchResultsWithPopular($storeIds, $query);

        $state = $this->flowState($phone);
        $state['last_search'] = $query;
        $state['store_results'] = $storeIds;
        $state['store_offset'] = 0;
        $state['selected_store_id'] = null;
        $this->saveFlowState($phone, $state);

        return $this->sendStoresPage($phone, 0);
    }

    private function padSearchResultsWithPopular(array $matchedIds, string $query): array
    {
        $minResults = 4;

        if (count($matchedIds) >= $minResults) {
            return $matchedIds;
        }

        $popularIds = ['pastel_do_zeca', 'burguer_centro', 'pizza_bela', 'poke_wave', 'sushi_zen', 'grelha_prime', 'shawarma_house', 'doceria_mila'];
        $extra = array_values(array_diff($popularIds, $matchedIds));
        $needed = $minResults - count($matchedIds);

        return array_merge($matchedIds, array_slice($extra, 0, $needed));
    }

    private function sendStoresPage(string $phone, int $offset): bool
    {
        $state = $this->flowState($phone);
        $storeIds = array_values(array_filter($state['store_results'] ?? [], fn (mixed $id) => is_string($id) && $id !== ''));

        if ($storeIds === []) {
            $storeIds = array_keys($this->storesCatalog());
        }

        $pageStoreIds = array_slice($storeIds, $offset, self::STORE_PAGE_SIZE);

        if ($pageStoreIds === []) {
            return false;
        }

        $catalog = $this->storesCatalog();
        $cards = [];

        foreach ($pageStoreIds as $storeId) {
            if (! array_key_exists($storeId, $catalog)) {
                continue;
            }

            $store = $catalog[$storeId];
            $cards[] = [
                'text' => $store['title'].' | ⭐ '.$store['rating']."\n".$this->buildStoreLogisticsLine($store),
                'image' => $store['image'],
                'buttons' => [
                    [
                        'id' => 'view_menu_'.$storeId,
                        'label' => 'Cardapio',
                        'type' => 'REPLY',
                    ],
                ],
            ];
        }

        if ($cards === []) {
            return false;
        }

        $nextOffset = $offset + count($pageStoreIds);

        if ($nextOffset < count($storeIds)) {
            $cards[] = [
                'text' => 'Ver mais lojas',
                'image' => (string) config('services.zapi.flow_more_image', 'https://picsum.photos/seed/mais-lojas/600/600'),
                'buttons' => [
                    [
                        'id' => 'view_more_'.$nextOffset,
                        'label' => 'Ver mais',
                        'type' => 'REPLY',
                    ],
                ],
            ];
        }

        $state['store_offset'] = $offset;
        $this->saveFlowState($phone, $state);

        try {
            $this->zapiClient->sendCarousel(
                $phone,
                'Vitrine de Lojas: escolha uma loja no carrossel ou toque em Ver mais.',
                $cards
            );

            return true;
        } catch (\Throwable $exception) {
            Log::warning('Failed to send store carousel.', [
                'phone' => $phone,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private function buildStoreLogisticsLine(array $store): string
    {
        $distance = (string) ($store['distance'] ?? '2,1 km');
        $shipping = (string) ($store['shipping'] ?? 'Frete Gratis');
        $eta = (string) ($store['eta'] ?? '30-40 min');

        return $distance.' • '.$shipping.' • '.$eta;
    }

    private function selectStore(string $phone, string $storeId): bool
    {
        $catalog = $this->storesCatalog();

        if (! array_key_exists($storeId, $catalog)) {
            return false;
        }

        $state = $this->flowState($phone);
        $state['selected_store_id'] = $storeId;
        $this->saveFlowState($phone, $state);

        return $this->sendProductsCarousel($phone, $storeId, 0);
    }

    private function sendProductsCarousel(string $phone, string $storeId, int $offset): bool
    {
        $catalog = $this->storesCatalog();

        if (! array_key_exists($storeId, $catalog)) {
            return false;
        }

        $store = $catalog[$storeId];
        $products = $store['products'];
        $pageProducts = array_slice($products, $offset, self::PRODUCT_PAGE_SIZE);

        if ($pageProducts === []) {
            return false;
        }

        $cards = [];

        foreach ($pageProducts as $product) {
            $cards[] = [
                'text' => $product['name'].' | R$ '.number_format((float) $product['price'], 2, ',', '.')."\n".$product['description'],
                'image' => $product['image'],
                'buttons' => [
                    [
                        'id' => 'flow_add_'.$storeId.'_'.$product['id'],
                        'label' => 'Adicionar',
                        'type' => 'REPLY',
                    ],
                    [
                        'id' => 'flow_cart',
                        'label' => 'Carrinho',
                        'type' => 'REPLY',
                    ],
                ],
            ];
        }

        $nextOffset = $offset + count($pageProducts);

        if ($nextOffset < count($products)) {
            $cards[] = [
                'text' => 'Mostrar mais produtos da loja',
                'image' => (string) config('services.zapi.flow_more_image', 'https://picsum.photos/seed/mais-lojas/600/600'),
                'buttons' => [
                    [
                        'id' => 'flow_product_more_'.$storeId.'_'.$nextOffset,
                        'label' => 'Mostrar mais',
                        'type' => 'REPLY',
                    ],
                ],
            ];
        }

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
            $this->zapiClient->sendCarousel($phone, 'Produtos da loja '.$store['title'].'.', $cards);

            return true;
        } catch (\Throwable $exception) {
            Log::warning('Failed to send products carousel.', [
                'phone' => $phone,
                'store_id' => $storeId,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private function addProductToCart(string $phone, string $storeId, string $productId): bool
    {
        $catalog = $this->storesCatalog();

        if (! array_key_exists($storeId, $catalog)) {
            return false;
        }

        $product = collect($catalog[$storeId]['products'])->first(fn (array $item): bool => $item['id'] === $productId);

        if (! is_array($product)) {
            return false;
        }

        $state = $this->flowState($phone);
        $cart = $state['cart'] ?? ['store_id' => null, 'items' => []];
        $switchedStore = false;

        if (($cart['store_id'] ?? null) !== null && $cart['store_id'] !== $storeId) {
            $cart = ['store_id' => $storeId, 'items' => []];
            $switchedStore = true;
        }

        $cart['store_id'] = $storeId;
        $cart['items'][$productId] = (int) ($cart['items'][$productId] ?? 0) + 1;

        $state['cart'] = $cart;
        $state['selected_store_id'] = $storeId;
        $this->saveFlowState($phone, $state);

        $notice = $switchedStore
            ? 'Seu carrinho foi reiniciado porque cada carrinho aceita produtos de apenas uma loja.\n\n'
            : '';

        $message = $notice.'Produto adicionado: '.$product['name'].'.\nDigite "carrinho" para revisar, "finalizar" para pagar ou "voltar lojas" para trocar de loja.';

        try {
            $this->zapiClient->sendText($phone, $message);

            return true;
        } catch (\Throwable $exception) {
            Log::warning('Failed to send add-to-cart response.', [
                'phone' => $phone,
                'store_id' => $storeId,
                'product_id' => $productId,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private function sendCartSummary(string $phone): bool
    {
        $state = $this->flowState($phone);
        $cart = $state['cart'] ?? null;

        if (! is_array($cart) || ! is_array($cart['items'] ?? null) || $cart['items'] === []) {
            try {
                $this->zapiClient->sendText($phone, 'Seu carrinho esta vazio. Escolha uma loja e adicione produtos.');

                return true;
            } catch (\Throwable $exception) {
                Log::warning('Failed to send empty-cart response.', [
                    'phone' => $phone,
                    'error' => $exception->getMessage(),
                ]);

                return false;
            }
        }

        $storeId = (string) ($cart['store_id'] ?? '');
        $catalog = $this->storesCatalog();

        if ($storeId === '' || ! array_key_exists($storeId, $catalog)) {
            return false;
        }

        $productsById = collect($catalog[$storeId]['products'])->keyBy('id');
        $lines = ['Carrinho - '.$catalog[$storeId]['title']];
        $total = 0.0;

        foreach ($cart['items'] as $productId => $qty) {
            $product = $productsById->get($productId);

            if (! is_array($product) || $qty < 1) {
                continue;
            }

            $lineTotal = ((float) $product['price']) * (int) $qty;
            $total += $lineTotal;
            $lines[] = '- '.$qty.'x '.$product['name'].' (R$ '.number_format($lineTotal, 2, ',', '.').')';
        }

        $lines[] = '';
        $lines[] = 'Total: R$ '.number_format($total, 2, ',', '.');
        $lines[] = 'Digite "finalizar" para pagar, "voltar lojas" para trocar loja ou continue adicionando produtos.';

        try {
            $this->zapiClient->sendText($phone, implode("\n", $lines));

            return true;
        } catch (\Throwable $exception) {
            Log::warning('Failed to send cart summary.', [
                'phone' => $phone,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private function finalizeCart(string $phone): bool
    {
        $state = $this->flowState($phone);
        $cart = $state['cart'] ?? null;

        if (! is_array($cart) || ! is_array($cart['items'] ?? null) || $cart['items'] === []) {
            try {
                $this->zapiClient->sendText($phone, 'Seu carrinho esta vazio. Adicione produtos antes de finalizar.');

                return true;
            } catch (\Throwable $exception) {
                Log::warning('Failed to send finalize-empty-cart response.', [
                    'phone' => $phone,
                    'error' => $exception->getMessage(),
                ]);

                return false;
            }
        }

        $storeId = (string) ($cart['store_id'] ?? '');
        $catalog = $this->storesCatalog();

        if ($storeId === '' || ! array_key_exists($storeId, $catalog)) {
            return false;
        }

        $productsById = collect($catalog[$storeId]['products'])->keyBy('id');
        $total = 0.0;

        foreach ($cart['items'] as $productId => $qty) {
            $product = $productsById->get($productId);

            if (! is_array($product) || $qty < 1) {
                continue;
            }

            $total += ((float) $product['price']) * (int) $qty;
        }

        $paymentLink = $this->buildPaymentLink($phone, $storeId, $cart['items'], $total);
        $amount = number_format($total, 2, ',', '.');

        try {
            $this->zapiClient->sendText(
                $phone,
                'Pedido pronto! Total: R$ '.$amount.'.\nPague no link: '.$paymentLink.'\nApos o pagamento, seguimos com a confirmacao.'
            );

            $state['last_checkout_amount'] = $total;
            $state['last_checkout_at'] = now()->toIso8601String();
            $state['last_payment_link'] = $paymentLink;
            $state['cart'] = ['store_id' => $storeId, 'items' => []];
            $this->saveFlowState($phone, $state);

            return true;
        } catch (\Throwable $exception) {
            Log::warning('Failed to send checkout payment link.', [
                'phone' => $phone,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private function returnToStores(string $phone): bool
    {
        $state = $this->flowState($phone);
        $state['selected_store_id'] = null;
        $state['store_offset'] = 0;
        $this->saveFlowState($phone, $state);

        return $this->sendStoresPage($phone, 0);
    }

    private function sendCategoryStores(string $phone, string $categoryId): bool
    {
        $categories = $this->categoryRows();

        if (! array_key_exists($categoryId, $categories)) {
            return false;
        }

        $state = $this->flowState($phone);
        $state['last_search'] = $categories[$categoryId]['title'];
        $state['store_results'] = $this->searchStoreIdsByTags($categories[$categoryId]['tags']);
        $state['store_offset'] = 0;
        $state['selected_store_id'] = null;
        $this->saveFlowState($phone, $state);

        return $this->sendStoresPage($phone, 0);
    }

    private function sendCategoryList(string $phone): bool
    {
        $message = trim((string) config('services.zapi.list_message'));
        $buttonText = trim((string) config('services.zapi.list_button_text'));
        $title = trim((string) config('services.zapi.list_title'));
        $description = trim((string) config('services.zapi.list_description'));
        $options = $this->buildCategoryListOptions();

        if ($message === '' || $buttonText === '' || $title === '' || $options === []) {
            return $this->sendAllStoresFallback($phone, 'Escolha uma categoria e depois a loja desejada.');
        }

        try {
            $composedMessage = $description !== '' ? $message."\n".$description : $message;
            $this->zapiClient->sendList($phone, $composedMessage, $buttonText, $title, $description, $options);

            return true;
        } catch (\Throwable $exception) {
            Log::warning('Failed to send Z-API category list.', [
                'phone' => $phone,
                'error' => $exception->getMessage(),
            ]);

            return $this->sendAllStoresFallback($phone, 'Nao consegui abrir a lista interativa. Mostrando as lojas direto no carrossel.');
        }
    }

    private function sendAllStoresFallback(string $phone, string $notice): bool
    {
        $state = $this->flowState($phone);
        $state['store_results'] = array_keys($this->storesCatalog());
        $state['store_offset'] = 0;
        $state['selected_store_id'] = null;
        $this->saveFlowState($phone, $state);

        try {
            $this->zapiClient->sendText($phone, $notice);
        } catch (\Throwable $exception) {
            Log::warning('Failed to send category fallback notice.', [
                'phone' => $phone,
                'error' => $exception->getMessage(),
            ]);
        }

        return $this->sendStoresPage($phone, 0);
    }

    private function handleCommerceReplyIntent(array $payload, string $phone, string $messageText): bool
    {
        $normalizedText = strtolower(trim($messageText));
        $buttonId = strtolower($this->resolveButtonReplyId($payload) ?? '');

        $catalogIntent = $buttonId !== '' && str_contains($buttonId, 'catalog')
            || str_contains($buttonId, 'menu-')
            || str_contains($normalizedText, 'cardapio')
            || str_contains($normalizedText, 'catalogo');

        if ($catalogIntent) {
            return $this->sendCatalogResponse($phone);
        }

        $productIntent = $buttonId !== '' && str_contains($buttonId, 'produto')
            || str_contains($normalizedText, 'produto');

        if ($productIntent) {
            return $this->sendProductResponse($phone);
        }

        return false;
    }

    private function buildPaymentLink(string $phone, string $storeId, array $items, float $total): string
    {
        $base = trim((string) config('services.zapi.payment_base_url', 'https://pagamento.deliveryzap.com/checkout'));

        if ($base === '') {
            $base = 'https://pagamento.deliveryzap.com/checkout';
        }

        $payload = [
            'phone' => $phone,
            'store' => $storeId,
            'amount' => number_format($total, 2, '.', ''),
            'items' => base64_encode(json_encode($items, JSON_THROW_ON_ERROR)),
            'reference' => Str::ulid()->toBase32(),
        ];

        return $base.'?'.http_build_query($payload);
    }

    private function sendCatalogResponse(string $phone): bool
    {
        $catalogPhone = trim((string) config('services.zapi.catalog_phone'));

        if ($catalogPhone === '') {
            return false;
        }

        try {
            $this->zapiClient->sendCatalog($phone, $catalogPhone, [
                'translation' => (string) config('services.zapi.catalog_translation'),
                'message' => (string) config('services.zapi.catalog_message'),
                'title' => (string) config('services.zapi.catalog_title'),
                'catalogDescription' => (string) config('services.zapi.catalog_description'),
            ]);

            return true;
        } catch (\Throwable $exception) {
            Log::warning('Failed to send Z-API catalog response.', [
                'phone' => $phone,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private function sendProductResponse(string $phone): bool
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

    private function eventType(array $payload): string
    {
        return (string) ($payload['type'] ?? $payload['event'] ?? 'unknown');
    }

    private function resolveExternalId(array $payload): ?string
    {
        $externalId = $payload['id']
            ?? $payload['messageId']
            ?? $payload['orderId']
            ?? data_get($payload, 'message.id')
            ?? data_get($payload, 'order.id');

        return $externalId !== null ? (string) $externalId : null;
    }

    private function extractDeliveryAttributes(array $payload): ?array
    {
        $orderCode = $this->extractScalarText([
            $payload['orderCode'] ?? null,
            data_get($payload, 'order.code'),
            data_get($payload, 'message.orderCode'),
        ], ['orderCode', 'code', 'id']);

        $customerName = $this->extractScalarText([
            $payload['customerName'] ?? null,
            data_get($payload, 'customer.name'),
            data_get($payload, 'message.customerName'),
            data_get($payload, 'message.senderName'),
        ], ['customerName', 'name']);

        $customerPhoneRaw = $this->extractScalarText([
            $payload['phone'] ?? null,
            data_get($payload, 'customer.phone'),
            data_get($payload, 'message.phone'),
            data_get($payload, 'from'),
            data_get($payload, 'sender.id'),
        ], ['phone', 'wa_id', 'id', 'from']);

        $customerPhone = null;

        if ($customerPhoneRaw !== null) {
            $digits = preg_replace('/\D+/', '', $customerPhoneRaw);
            $customerPhone = $digits !== '' ? $digits : $customerPhoneRaw;
        }

        if ($orderCode === null && $customerPhone === null) {
            return null;
        }

        $statusValue = $payload['status']
            ?? data_get($payload, 'order.status')
            ?? data_get($payload, 'message.status')
            ?? 'new';

        $status = strtolower($this->extractScalarText($statusValue, ['status', 'text']) ?? 'new');

        $totalValue = $payload['total']
            ?? data_get($payload, 'order.total')
            ?? 0;

        $totalAmount = is_numeric($totalValue)
            ? (float) $totalValue
            : (float) ($this->extractScalarText($totalValue, ['total', 'amount', 'value']) ?? 0);

        $updatedAt = $payload['updatedAt']
            ?? data_get($payload, 'order.updatedAt')
            ?? data_get($payload, 'message.timestamp');

        $address = $this->extractScalarText([
            $payload['address'] ?? null,
            data_get($payload, 'customer.address'),
            data_get($payload, 'order.address'),
        ], ['address', 'street', 'line1', 'formatted']);

        return [
            'external_id' => $this->resolveExternalId($payload),
            'order_code' => $orderCode,
            'customer_name' => $customerName,
            'customer_phone' => $customerPhone,
            'address' => $address,
            'status' => $status,
            'total_amount' => $totalAmount,
            'source' => 'zapi',
            'last_update_at' => $this->resolveDateTime($updatedAt),
            'raw_payload' => $payload,
        ];
    }

    private function resolveDateTime(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Z-API pode enviar timestamp unix, milissegundos ou string de data.
        if (is_numeric($value)) {
            $numeric = (int) $value;

            if ($numeric > 9999999999) {
                return CarbonImmutable::createFromTimestampMs($numeric);
            }

            return CarbonImmutable::createFromTimestamp($numeric);
        }

        try {
            return CarbonImmutable::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function isOutgoingMessage(array $payload): bool
    {
        $fromMe = $payload['fromMe']
            ?? data_get($payload, 'message.fromMe')
            ?? data_get($payload, 'isFromMe');

        return filter_var($fromMe, FILTER_VALIDATE_BOOLEAN);
    }

    private function resolveIncomingPhone(array $payload): ?string
    {
        $candidates = [
            $payload['phone'] ?? null,
            data_get($payload, 'message.phone'),
            data_get($payload, 'phoneNumber'),
            data_get($payload, 'from'),
            data_get($payload, 'sender.phone'),
            data_get($payload, 'sender.id'),
        ];

        foreach ($candidates as $candidate) {
            $phone = $this->extractScalarText($candidate, ['phone', 'id', 'from', 'wa_id']);

            if ($phone === null) {
                continue;
            }

            $normalized = preg_replace('/\D+/', '', $phone);

            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }

    private function resolveIncomingMessageText(array $payload): ?string
    {
        $candidates = [
            $payload['text'] ?? null,
            data_get($payload, 'text.message'),
            data_get($payload, 'text.body'),
            data_get($payload, 'buttonReply.message'),
            data_get($payload, 'listResponse.title'),
            data_get($payload, 'message.listResponse.title'),
            data_get($payload, 'optionListResponse.title'),
            data_get($payload, 'message.optionListResponse.title'),
            data_get($payload, 'button.text'),
            data_get($payload, 'buttonReply.text'),
            data_get($payload, 'message.text'),
            data_get($payload, 'message.body'),
            data_get($payload, 'message.content.text'),
            data_get($payload, 'body'),
            data_get($payload, 'message'),
        ];

        foreach ($candidates as $candidate) {
            $text = $this->extractScalarText($candidate, ['text', 'message', 'body', 'caption', 'content']);

            if ($text !== null) {
                return $text;
            }
        }

        return null;
    }

    private function resolveButtonReplyId(array $payload): ?string
    {
        $candidates = [
            data_get($payload, 'buttonId'),
            data_get($payload, 'selectedId'),
            data_get($payload, 'selectedButtonId'),
            data_get($payload, 'message.selectedId'),
            data_get($payload, 'button.id'),
            data_get($payload, 'buttonReply.buttonId'),
            data_get($payload, 'message.buttonId'),
            data_get($payload, 'message.button.id'),
            data_get($payload, 'message.selectedButtonId'),
            data_get($payload, 'buttonReply.id'),
            data_get($payload, 'buttonsResponseMessage.selectedButtonId'),
            data_get($payload, 'message.buttonsResponseMessage.selectedButtonId'),
            data_get($payload, 'templateButtonReplyMessage.selectedId'),
            data_get($payload, 'message.templateButtonReplyMessage.selectedId'),
            data_get($payload, 'optionListResponse.selectedId'),
            data_get($payload, 'message.optionListResponse.selectedId'),
            data_get($payload, 'listResponse.singleSelectReply.selectedRowId'),
            data_get($payload, 'message.listResponse.singleSelectReply.selectedRowId'),
        ];

        foreach ($candidates as $candidate) {
            $id = $this->extractScalarText($candidate, ['id', 'buttonId', 'selectedId', 'selectedRowId']);

            if ($id !== null) {
                return $id;
            }
        }

        return null;
    }

    private function resolveSelectedCategoryId(array $payload): ?string
    {
        $candidates = [
            data_get($payload, 'selectedId'),
            data_get($payload, 'listResponse.singleSelectReply.selectedRowId'),
            data_get($payload, 'message.listResponse.singleSelectReply.selectedRowId'),
            data_get($payload, 'optionListResponse.selectedId'),
            data_get($payload, 'message.optionListResponse.selectedId'),
            data_get($payload, 'selectedRowId'),
            data_get($payload, 'listResponse.title'),
            data_get($payload, 'message.listResponse.title'),
            data_get($payload, 'optionListResponse.title'),
            data_get($payload, 'message.optionListResponse.title'),
        ];

        $categories = $this->categoryRows();

        foreach ($candidates as $candidate) {
            $id = $this->extractScalarText($candidate, ['selectedRowId', 'id']);

            if ($id !== null && str_starts_with(strtoupper($id), 'CAT_')) {
                return strtoupper($id);
            }

            if ($id === null) {
                continue;
            }

            $normalized = $this->normalizeText($id);

            foreach ($categories as $category) {
                if ($normalized === $this->normalizeText($category['title'])) {
                    return $category['id'];
                }
            }
        }

        return null;
    }

    private function buildCategoryListOptions(): array
    {
        return array_values($this->categoryRows());
    }

    private function categoryRows(): array
    {
        return [
            'CAT_BURGERS' => [
                'id' => 'CAT_BURGERS',
                'title' => 'Hamburgueres Artesanais',
                'description' => 'Blend de 180g grelhado no fogo.',
                'tags' => ['hamburguer', 'burger', 'lanche'],
            ],
            'CAT_COMBOS' => [
                'id' => 'CAT_COMBOS',
                'title' => 'Combos Promocionais',
                'description' => 'Burger + Batata + Refri com desconto.',
                'tags' => ['combo', 'promocao', 'oferta'],
            ],
            'CAT_BEBIDAS' => [
                'id' => 'CAT_BEBIDAS',
                'title' => 'Bebidas',
                'description' => 'Sucos naturais, refris e cervejas.',
                'tags' => ['bebida', 'suco', 'refrigerante'],
            ],
            'CAT_SOBREMESAS' => [
                'id' => 'CAT_SOBREMESAS',
                'title' => 'Sobremesas',
                'description' => 'Milk-shake, brownie e acai.',
                'tags' => ['sobremesa', 'doce', 'acai'],
            ],
        ];
    }

    private function extractScalarText(mixed $value, array $preferredKeys = []): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) || is_numeric($value)) {
            $text = trim((string) $value);

            return $text !== '' ? $text : null;
        }

        if (is_array($value)) {
            foreach ($preferredKeys as $key) {
                if (array_key_exists($key, $value)) {
                    $text = $this->extractScalarText($value[$key], $preferredKeys);

                    if ($text !== null) {
                        return $text;
                    }
                }
            }

            foreach ($value as $item) {
                $text = $this->extractScalarText($item, $preferredKeys);

                if ($text !== null) {
                    return $text;
                }
            }
        }

        return null;
    }

    private function storesCatalog(): array
    {
        return [
            'burguer_centro' => [
                'id' => 'burguer_centro',
                'title' => 'Burguer Centro',
                'description' => 'Smash burgers artesanais e combos para a noite.',
                'rating' => '4.8',
                'distance' => '1,8 km',
                'shipping' => 'Frete Gratis',
                'eta' => '25-35 min',
                'image' => 'https://picsum.photos/seed/burguer-centro/600/600',
                'tags' => ['burger', 'hamburguer', 'lanche', 'combo'],
                'products' => [
                    ['id' => 'bc_smash', 'name' => 'Smash Classico', 'description' => 'Pao brioche, blend 120g e queijo.', 'price' => 24.90, 'image' => 'https://picsum.photos/seed/bc-smash/600/600'],
                    ['id' => 'bc_bacon', 'name' => 'Smash Bacon', 'description' => 'Smash com bacon crocante e molho especial.', 'price' => 29.90, 'image' => 'https://picsum.photos/seed/bc-bacon/600/600'],
                    ['id' => 'bc_combo', 'name' => 'Combo Smash + Fritas', 'description' => 'Smash classico com fritas media.', 'price' => 34.90, 'image' => 'https://picsum.photos/seed/bc-combo/600/600'],
                ],
            ],
            'pizza_bela' => [
                'id' => 'pizza_bela',
                'title' => 'Pizza Bela',
                'description' => 'Pizzas de longa fermentacao e borda recheada.',
                'rating' => '4.7',
                'distance' => '2,4 km',
                'shipping' => 'Frete R$ 4,99',
                'eta' => '35-45 min',
                'image' => 'https://picsum.photos/seed/pizza-bela/600/600',
                'tags' => ['pizza', 'italiana', 'jantar', 'combo'],
                'products' => [
                    ['id' => 'pb_marg', 'name' => 'Pizza Margherita', 'description' => 'Molho italiano, muzzarela e manjericao.', 'price' => 52.00, 'image' => 'https://picsum.photos/seed/pb-marg/600/600'],
                    ['id' => 'pb_calab', 'name' => 'Pizza Calabresa', 'description' => 'Calabresa artesanal e cebola roxa.', 'price' => 56.00, 'image' => 'https://picsum.photos/seed/pb-calab/600/600'],
                    ['id' => 'pb_refri', 'name' => 'Refrigerante 2L', 'description' => 'Refrigerante gelado para acompanhar.', 'price' => 12.00, 'image' => 'https://picsum.photos/seed/pb-refri/600/600'],
                ],
            ],
            'poke_wave' => [
                'id' => 'poke_wave',
                'title' => 'Poke Wave',
                'description' => 'Pokes frescos, leves e montados na hora.',
                'rating' => '4.9',
                'distance' => '3,1 km',
                'shipping' => 'Frete Gratis',
                'eta' => '20-30 min',
                'image' => 'https://picsum.photos/seed/poke-wave/600/600',
                'tags' => ['poke', 'saudavel', 'japones', 'fit'],
                'products' => [
                    ['id' => 'pw_salmao', 'name' => 'Poke de Salmao', 'description' => 'Salmao, arroz gohan e molho citrus.', 'price' => 41.90, 'image' => 'https://picsum.photos/seed/pw-salmao/600/600'],
                    ['id' => 'pw_frango', 'name' => 'Poke de Frango', 'description' => 'Frango grelhado, legumes e molho teriyaki.', 'price' => 34.90, 'image' => 'https://picsum.photos/seed/pw-frango/600/600'],
                    ['id' => 'pw_guarana', 'name' => 'Guarana Zero', 'description' => 'Lata 350ml.', 'price' => 6.90, 'image' => 'https://picsum.photos/seed/pw-guarana/600/600'],
                ],
            ],
            'doceria_mila' => [
                'id' => 'doceria_mila',
                'title' => 'Doceria Mila',
                'description' => 'Sobremesas, brownies e acai cremoso.',
                'rating' => '4.8',
                'distance' => '2,0 km',
                'shipping' => 'Frete R$ 3,99',
                'eta' => '20-35 min',
                'image' => 'https://picsum.photos/seed/doceria-mila/600/600',
                'tags' => ['sobremesa', 'doce', 'brownie', 'acai'],
                'products' => [
                    ['id' => 'dm_brownie', 'name' => 'Brownie com Nutella', 'description' => 'Brownie de chocolate com cobertura.', 'price' => 18.90, 'image' => 'https://picsum.photos/seed/dm-brownie/600/600'],
                    ['id' => 'dm_acai', 'name' => 'Acai 500ml', 'description' => 'Acai cremoso com 3 adicionais.', 'price' => 22.00, 'image' => 'https://picsum.photos/seed/dm-acai/600/600'],
                    ['id' => 'dm_milk', 'name' => 'Milk-shake Morango', 'description' => 'Shake cremoso 400ml.', 'price' => 19.50, 'image' => 'https://picsum.photos/seed/dm-milk/600/600'],
                ],
            ],
            'sushi_zen' => [
                'id' => 'sushi_zen',
                'title' => 'Sushi Zen',
                'description' => 'Combinados premium e sashimis frescos.',
                'rating' => '4.7',
                'distance' => '4,2 km',
                'shipping' => 'Frete R$ 7,90',
                'eta' => '35-50 min',
                'image' => 'https://picsum.photos/seed/sushi-zen/600/600',
                'tags' => ['japones', 'sushi', 'sashimi', 'jantar'],
                'products' => [
                    ['id' => 'sz_combo20', 'name' => 'Combo 20 pecas', 'description' => 'Selecao de uramaki, niguiri e hot roll.', 'price' => 58.00, 'image' => 'https://picsum.photos/seed/sz-combo20/600/600'],
                    ['id' => 'sz_combo40', 'name' => 'Combo 40 pecas', 'description' => 'Combinado familia com sashimi.', 'price' => 109.00, 'image' => 'https://picsum.photos/seed/sz-combo40/600/600'],
                    ['id' => 'sz_sake', 'name' => 'Temaki Salmao', 'description' => 'Temaki tradicional de salmao.', 'price' => 26.00, 'image' => 'https://picsum.photos/seed/sz-temaki/600/600'],
                ],
            ],
            'grelha_prime' => [
                'id' => 'grelha_prime',
                'title' => 'Grelha Prime',
                'description' => 'Pratos de carne, frango e acompanhamentos.',
                'rating' => '4.6',
                'distance' => '3,6 km',
                'shipping' => 'Frete R$ 5,99',
                'eta' => '30-40 min',
                'image' => 'https://picsum.photos/seed/grelha-prime/600/600',
                'tags' => ['churrasco', 'carne', 'prato executivo'],
                'products' => [
                    ['id' => 'gp_picanha', 'name' => 'Picanha na Chapa', 'description' => 'Picanha com arroz, fritas e farofa.', 'price' => 67.90, 'image' => 'https://picsum.photos/seed/gp-picanha/600/600'],
                    ['id' => 'gp_frango', 'name' => 'Frango Grelhado', 'description' => 'Peito grelhado e legumes salteados.', 'price' => 39.90, 'image' => 'https://picsum.photos/seed/gp-frango/600/600'],
                    ['id' => 'gp_limo', 'name' => 'Limonada 500ml', 'description' => 'Limonada natural gelada.', 'price' => 8.50, 'image' => 'https://picsum.photos/seed/gp-limo/600/600'],
                ],
            ],
            'pastel_do_zeca' => [
                'id' => 'pastel_do_zeca',
                'title' => 'Pastel do Zeca',
                'description' => 'Pasteis sequinhos, caldo de cana e porcoes.',
                'rating' => '4.9',
                'distance' => '1,2 km',
                'shipping' => 'Frete Gratis',
                'eta' => '15-25 min',
                'image' => 'https://picsum.photos/seed/pastel-do-zeca/600/600',
                'tags' => ['pastel', 'feira', 'salgado', 'lanche', 'pastelaria'],
                'products' => [
                    ['id' => 'pz_carne', 'name' => 'Pastel de Carne', 'description' => 'Massa crocante com recheio de carne temperada.', 'price' => 12.90, 'image' => 'https://picsum.photos/seed/pz-carne/600/600'],
                    ['id' => 'pz_queijo', 'name' => 'Pastel de Queijo', 'description' => 'Queijo derretido e massa dourada.', 'price' => 11.90, 'image' => 'https://picsum.photos/seed/pz-queijo/600/600'],
                    ['id' => 'pz_frango', 'name' => 'Pastel de Frango com Catupiry', 'description' => 'Frango desfiado com catupiry cremoso.', 'price' => 13.90, 'image' => 'https://picsum.photos/seed/pz-frango/600/600'],
                    ['id' => 'pz_pizza', 'name' => 'Pastel de Pizza', 'description' => 'Tomate, muzzarela e oregano.', 'price' => 13.90, 'image' => 'https://picsum.photos/seed/pz-pizza/600/600'],
                    ['id' => 'pz_caldo', 'name' => 'Caldo de Cana 500ml', 'description' => 'Caldo de cana natural e gelado.', 'price' => 9.00, 'image' => 'https://picsum.photos/seed/pz-caldo/600/600'],
                    ['id' => 'pz_porcao', 'name' => 'Porcao de Coxinha (6un)', 'description' => 'Coxinhas crocantes de frango.', 'price' => 22.00, 'image' => 'https://picsum.photos/seed/pz-porcao/600/600'],
                ],
            ],
            'pastel_da_dona_maria' => [
                'id' => 'pastel_da_dona_maria',
                'title' => 'Pastel da Dona Maria',
                'description' => 'Receita de familia, pastel artesanal na chapa.',
                'rating' => '4.8',
                'distance' => '1,9 km',
                'shipping' => 'Frete Gratis',
                'eta' => '20-30 min',
                'image' => 'https://picsum.photos/seed/pastel-dona-maria/600/600',
                'tags' => ['pastel', 'pastelaria', 'salgado', 'artesanal'],
                'products' => [
                    ['id' => 'dm2_carne', 'name' => 'Pastel de Carne Artesanal', 'description' => 'Carne moida com batata e temperinhos da Dona Maria.', 'price' => 14.90, 'image' => 'https://picsum.photos/seed/dm2-carne/600/600'],
                    ['id' => 'dm2_camarao', 'name' => 'Pastel de Camarao', 'description' => 'Camarao salteado com requeijao.', 'price' => 18.90, 'image' => 'https://picsum.photos/seed/dm2-camarao/600/600'],
                    ['id' => 'dm2_palmito', 'name' => 'Pastel de Palmito', 'description' => 'Palmito pupunha com muzzarela.', 'price' => 13.90, 'image' => 'https://picsum.photos/seed/dm2-palmito/600/600'],
                    ['id' => 'dm2_suco', 'name' => 'Suco de Laranja 400ml', 'description' => 'Laranja espremida na hora.', 'price' => 10.00, 'image' => 'https://picsum.photos/seed/dm2-suco/600/600'],
                ],
            ],
            'pastelao_express' => [
                'id' => 'pastelao_express',
                'title' => 'Pastelao Express',
                'description' => 'Pasteis gigantes e combos completos.',
                'rating' => '4.7',
                'distance' => '2,5 km',
                'shipping' => 'Frete R$ 3,99',
                'eta' => '20-30 min',
                'image' => 'https://picsum.photos/seed/pastelao-express/600/600',
                'tags' => ['pastel', 'pastelao', 'pastelaria', 'salgado', 'combo'],
                'products' => [
                    ['id' => 'pe_gigante', 'name' => 'Pastelao de Carne', 'description' => 'Pastel tamanho gigante com carne temperada.', 'price' => 22.90, 'image' => 'https://picsum.photos/seed/pe-gigante/600/600'],
                    ['id' => 'pe_combo', 'name' => 'Combo 3 Pasteis + Caldo', 'description' => '3 pasteis a escolha + caldo de cana 500ml.', 'price' => 39.90, 'image' => 'https://picsum.photos/seed/pe-combo/600/600'],
                    ['id' => 'pe_mini', 'name' => 'Mini Pasteis (10un)', 'description' => 'Mix de sabores em versao mini.', 'price' => 29.90, 'image' => 'https://picsum.photos/seed/pe-mini/600/600'],
                ],
            ],
            'taco_loco' => [
                'id' => 'taco_loco',
                'title' => 'Taco Loco',
                'description' => 'Comida mexicana com burritos e nachos.',
                'rating' => '4.7',
                'distance' => '2,9 km',
                'shipping' => 'Frete R$ 6,49',
                'eta' => '30-40 min',
                'image' => 'https://picsum.photos/seed/taco-loco/600/600',
                'tags' => ['mexicano', 'taco', 'burrito', 'nachos'],
                'products' => [
                    ['id' => 'tl_taco', 'name' => 'Taco de Frango', 'description' => 'Tortilha crocante com frango e guacamole.', 'price' => 21.90, 'image' => 'https://picsum.photos/seed/tl-taco/600/600'],
                    ['id' => 'tl_burrito', 'name' => 'Burrito de Carne', 'description' => 'Burrito recheado com carne e feijao.', 'price' => 29.90, 'image' => 'https://picsum.photos/seed/tl-burrito/600/600'],
                    ['id' => 'tl_nachos', 'name' => 'Nachos Supreme', 'description' => 'Nachos com cheddar, chili e jalapeno.', 'price' => 26.50, 'image' => 'https://picsum.photos/seed/tl-nachos/600/600'],
                ],
            ],
            'veg_garden' => [
                'id' => 'veg_garden',
                'title' => 'Veg Garden',
                'description' => 'Pratos vegetarianos e opcoes veganas.',
                'rating' => '4.8',
                'distance' => '3,3 km',
                'shipping' => 'Frete Gratis',
                'eta' => '25-35 min',
                'image' => 'https://picsum.photos/seed/veg-garden/600/600',
                'tags' => ['vegano', 'vegetariano', 'saudavel', 'salada'],
                'products' => [
                    ['id' => 'vg_bowl', 'name' => 'Bowl Proteico', 'description' => 'Graos, legumes e molho de tahine.', 'price' => 33.90, 'image' => 'https://picsum.photos/seed/vg-bowl/600/600'],
                    ['id' => 'vg_wrap', 'name' => 'Wrap Vegano', 'description' => 'Wrap integral com falafel e salada.', 'price' => 27.90, 'image' => 'https://picsum.photos/seed/vg-wrap/600/600'],
                    ['id' => 'vg_suco', 'name' => 'Suco Verde', 'description' => 'Couve, maca e gengibre.', 'price' => 12.90, 'image' => 'https://picsum.photos/seed/vg-suco/600/600'],
                ],
            ],
            'shawarma_house' => [
                'id' => 'shawarma_house',
                'title' => 'Shawarma House',
                'description' => 'Esfihas, shawarma e cozinha arabe.',
                'rating' => '4.8',
                'distance' => '2,7 km',
                'shipping' => 'Frete R$ 4,49',
                'eta' => '25-40 min',
                'image' => 'https://picsum.photos/seed/shawarma-house/600/600',
                'tags' => ['arabe', 'shawarma', 'esfiha', 'kibe'],
                'products' => [
                    ['id' => 'sh_shawarma', 'name' => 'Shawarma de Frango', 'description' => 'Pao folha com frango e molho garlic.', 'price' => 24.90, 'image' => 'https://picsum.photos/seed/sh-shawarma/600/600'],
                    ['id' => 'sh_esfiha', 'name' => 'Esfiha de Carne', 'description' => 'Massa macia com recheio de carne.', 'price' => 8.90, 'image' => 'https://picsum.photos/seed/sh-esfiha/600/600'],
                    ['id' => 'sh_kibe', 'name' => 'Kibe Frito', 'description' => 'Porcao com 6 unidades.', 'price' => 19.90, 'image' => 'https://picsum.photos/seed/sh-kibe/600/600'],
                ],
            ],
        ];
    }

    private function searchStoreIds(string $query): array
    {
        $catalog = $this->storesCatalog();
        $normalizedQuery = Str::of($query)->lower()->ascii()->toString();

        if (trim($normalizedQuery) === '') {
            return array_keys($catalog);
        }

        $matches = [];

        foreach ($catalog as $storeId => $store) {
            $haystacks = [
                Str::of($store['title'])->lower()->ascii()->toString(),
                Str::of($store['description'])->lower()->ascii()->toString(),
            ];

            foreach ($store['tags'] as $tag) {
                $haystacks[] = Str::of((string) $tag)->lower()->ascii()->toString();
            }

            foreach ($haystacks as $haystack) {
                if (str_contains($haystack, $normalizedQuery)) {
                    $matches[] = $storeId;
                    break;
                }
            }
        }

        return $matches;
    }

    private function searchStoreIdsByTags(array $tags): array
    {
        if ($tags === []) {
            return array_keys($this->storesCatalog());
        }

        $catalog = $this->storesCatalog();
        $normalizedTags = array_map(
            fn (mixed $tag): string => Str::of((string) $tag)->lower()->ascii()->toString(),
            $tags
        );

        $matches = [];

        foreach ($catalog as $storeId => $store) {
            $storeTags = array_map(
                fn (mixed $tag): string => Str::of((string) $tag)->lower()->ascii()->toString(),
                $store['tags']
            );

            if (count(array_intersect($normalizedTags, $storeTags)) > 0) {
                $matches[] = $storeId;
            }
        }

        return $matches;
    }

    private function normalizeText(string $text): string
    {
        return Str::of($text)
            ->lower()
            ->ascii()
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->toString();
    }

    private function flowState(string $phone): array
    {
        $state = Cache::get($this->flowStateCacheKey($phone), []);

        return is_array($state) ? $state : [];
    }

    private function saveFlowState(string $phone, array $state): void
    {
        Cache::put(
            $this->flowStateCacheKey($phone),
            $state,
            now()->addMinutes((int) config('services.zapi.flow_state_ttl_minutes', 180))
        );
    }

    private function resetFlowState(string $phone): void
    {
        Cache::forget($this->flowStateCacheKey($phone));
    }

    private function flowStateCacheKey(string $phone): string
    {
        return self::FLOW_STATE_CACHE_PREFIX.$phone;
    }
}
