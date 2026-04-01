<?php

namespace App\Services\Zapi;

use App\Models\Category;
use App\Models\Delivery;
use App\Models\Product;
use App\Models\Store;
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

    public function maybeSendAutoReply(array $payload): void
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
        $selectedCategorySlug = $this->resolveSelectedCategoryId($payload);

        if ($selectedCategorySlug !== null) {
            return $this->sendCategoryStores($phone, $selectedCategorySlug);
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

        if (in_array($normalizedText, ['ver categorias', 'categorias'], true)) {
            return $this->sendCategoriesCarousel($phone);
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
            $allStoreIds = Store::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->pluck('slug')
                ->filter(fn (mixed $slug): bool => is_string($slug) && trim($slug) !== '')
                ->values()
                ->all();

            if ($allStoreIds === []) {
                return false;
            }

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
        if ($buttonId === 'btn_ver_lojas') {
            return $this->sendAllStoresFallback($phone, 'Aqui esta a vitrine de lojas ativas.');
        }

        if ($buttonId === 'btn_ver_categorias') {
            return $this->sendCategoriesCarousel($phone);
        }

        if ($buttonId === 'btn_como_funciona') {
            return $this->sendHowItWorks($phone);
        }

        if (str_starts_with($buttonId, 'buscar_cat_')) {
            return $this->sendStoresByCategoryButtonPayload($phone, $buttonId);
        }

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
        if (preg_match('/^flow_add_([a-z0-9_\-]+)_(\d+)$/', $buttonId, $matches) !== 1) {
            return null;
        }

        return [
            'store_id' => $matches[1],
            'product_id' => $matches[2],
        ];
    }

    private function sendWelcomePrompt(string $phone): bool
    {
        $message = "Olá! 👋 Bem-vindo ao Zapediu!\n\nEstou aqui para matar a sua fome em poucos segundos. 🛵💨\n\nVocê pode simplesmente me dizer o que quer comer, por exemplo:\n🍔 \"Quero um hambúrguer\"\n🍕 \"Me mostre as pizzarias\"\n🏪 \"Cardápio do Pastel do Zeca\"\n\nOu, se preferir, escolha uma das opções abaixo:";
        $fallbackMessage = "Olá! 👋 Bem-vindo ao Zapediu!\n\nEstou aqui para matar a sua fome em poucos segundos.\n\nVocê pode me dizer o que quer comer, por exemplo:\n- Quero um hambúrguer\n- Me mostre as pizzarias\n- Cardápio do Pastel do Zeca\n\nOu digite:\n- ver lojas\n- ver categorias\n- como funciona";

        $state = $this->flowState($phone);
        $state['welcomed'] = true;
        $this->saveFlowState($phone, $state);

        try {
            $this->zapiClient->sendButtonActions($phone, $message, [
                ['id' => 'btn_ver_lojas', 'label' => '🏪 Ver Lojas'],
                ['id' => 'btn_ver_categorias', 'label' => '🍔 Ver Categorias'],
                ['id' => 'btn_como_funciona', 'label' => '❓ Como funciona'],
            ]);

            return true;
        } catch (\Throwable $exception) {
            Log::warning('Failed to send Z-API welcome prompt.', [
                'phone' => $phone,
                'error' => $exception->getMessage(),
            ]);

            try {
                $this->zapiClient->sendText($phone, $fallbackMessage);

                return true;
            } catch (\Throwable $fallbackException) {
                Log::warning('Failed to send Z-API welcome prompt fallback.', [
                    'phone' => $phone,
                    'error' => $fallbackException->getMessage(),
                ]);

                return false;
            }
        }
    }

    private function sendHowItWorks(string $phone): bool
    {
        $message = 'Voce escolhe uma categoria, seleciona uma loja e finaliza tudo no WhatsApp. Simples e rapido.';

        try {
            $this->zapiClient->sendText($phone, $message);

            return true;
        } catch (\Throwable $exception) {
            Log::warning('Failed to send how-it-works message.', [
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

        $popularIds = Store::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('slug')
            ->filter(fn (mixed $slug): bool => is_string($slug) && trim($slug) !== '')
            ->values()
            ->all();

        $extra = array_values(array_diff($popularIds, $matchedIds));
        $needed = $minResults - count($matchedIds);

        return array_merge($matchedIds, array_slice($extra, 0, $needed));
    }

    private function sendStoresPage(string $phone, int $offset): bool
    {
        $state = $this->flowState($phone);
        $storeIds = array_values(array_filter($state['store_results'] ?? [], fn (mixed $id) => is_string($id) && $id !== ''));

        if ($storeIds === []) {
            $storeIds = Store::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->pluck('slug')
                ->filter(fn (mixed $slug): bool => is_string($slug) && trim($slug) !== '')
                ->values()
                ->all();
        }

        $pageStoreIds = array_slice($storeIds, $offset, self::STORE_PAGE_SIZE);

        if ($pageStoreIds === []) {
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

            if (! $store instanceof Store) {
                continue;
            }

            $cards[] = [
                'text' => $this->formatStoreCardText($store),
                'image' => $store->cover_image_url
                    ?? $store->logo_url
                    ?? 'https://picsum.photos/seed/loja-'.urlencode((string) $store->slug).'/600/600',
                'buttons' => [
                    [
                        'id' => 'view_menu_'.$store->slug,
                        'label' => '📖 Ver Cardápio',
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
                '🏪 Lojas disponíveis — toque em Ver Cardápio para explorar',
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

    private function buildStoreLogisticsLine(mixed $store): string
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

    private function formatStoreCardText(Store $store): string
    {
        $headline = $store->name.' '.$this->storeCategoryEmoji($store);
        $meta = '⭐ '.$this->buildStoreRating($store).' | 🛵 '.$this->buildStoreEta($store);
        $description = trim((string) ($store->description ?? 'O melhor da categoria no marketplace Zapediu.'));

        if (mb_strlen($description) > 70) {
            $description = rtrim(mb_substr($description, 0, 67)).'...';
        }

        return $headline."\n".$meta."\n\n💬 \"".$description."\"";
    }

    private function storeCategoryEmoji(Store $store): string
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

    private function buildStoreRating(Store $store): string
    {
        $seed = abs(crc32((string) ($store->slug ?: $store->id)));

        return number_format(4.2 + (($seed % 8) / 10), 1, '.', '');
    }

    private function buildStoreEta(Store $store): string
    {
        $seed = abs(crc32((string) ($store->slug ?: $store->id)));
        $etas = ['15-25 min', '20-30 min', '25-35 min', '30-40 min'];

        return $etas[$seed % count($etas)];
    }

    private function selectStore(string $phone, string $storeId): bool
    {
        $store = Store::query()
            ->where('is_active', true)
            ->where('slug', $storeId)
            ->first();

        if ($store === null) {
            return false;
        }

        $state = $this->flowState($phone);
        $state['selected_store_id'] = $store->slug;
        $this->saveFlowState($phone, $state);

        return $this->sendProductsCarousel($phone, (string) $store->slug, 0);
    }

    private function sendProductsCarousel(string $phone, string $storeId, int $offset): bool
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
                'text' => $this->formatProductCardText($product, $store),
                'image' => $product->image_url
                    ?? (is_string($product->image_path) && $product->image_path !== '' ? $product->image_path : null)
                    ?? 'https://picsum.photos/seed/produto-'.(int) $product->id.'/600/600',
                'buttons' => [
                    [
                        'id' => 'flow_add_'.$store->slug.'_'.(int) $product->id,
                        'label' => '➕ Adicionar.',
                        'type' => 'REPLY',
                    ],
                ],
            ];
        }

        $nextOffset = $offset + count($pageProducts);

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
            $this->zapiClient->sendCarousel($phone, $this->buildMenuIntroMessage($store), $cards);

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
        $store = Store::query()
            ->where('is_active', true)
            ->where('slug', $storeId)
            ->first();

        if ($store === null) {
            return false;
        }

        $product = Product::query()
            ->where('is_active', true)
            ->where('store_id', $store->id)
            ->where('id', (int) $productId)
            ->first();

        if ($product === null) {
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

        $message = $notice.'Produto adicionado: '.$product->name.'.\nDigite "carrinho" para revisar, "finalizar" para pagar ou "voltar lojas" para trocar de loja.';

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

    private function buildCategoryHeader(Category $category): string
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

    private function buildMenuIntroMessage(Store $store): string
    {
        return '📖 Cardápio: '.$store->name." 📖\n\n"
            .'Deslize para o lado, escolha o seu pedido e clique em Adicionar'
            ."\n";
    }

    private function formatProductCardText(Product $product, Store $store): string
    {
        $name = trim((string) $product->name);
        $price = 'R$ '.number_format((float) $product->price, 2, ',', '.');
        $description = $this->normalizeProductDescription((string) ($product->description ?? 'Produto saboroso.'));

        return $name.' '.$this->productEmoji($product, $store)."\n\n"
            .'🏷️ Por: '.$price."\n\n"
            .'💬 "'.$description.'"';
    }

    private function normalizeProductDescription(string $description): string
    {
        $description = trim($description);

        if ($description === '') {
            return 'Produto saboroso.';
        }

        if (! str_ends_with($description, '.') && ! str_ends_with($description, '!') && ! str_ends_with($description, '?')) {
            $description .= '.';
        }

        return $description;
    }

    private function productEmoji(Product $product, Store $store): string
    {
        $slug = (string) ($store->category?->slug ?? '');

        return match ($slug) {
            'cat_lanches' => '🍔',
            'cat_pastel' => '🥟',
            'cat_pizza' => '🍕',
            'cat_acai' => '🍇',
            'cat_refeicao' => '🍽️',
            'cat_farmacia' => '💊',
            'cat_padaria' => '🥖',
            'cat_mercadinho' => '🛒',
            default => '🍽️',
        };
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
        $store = Store::query()->where('slug', $storeId)->first();

        if ($storeId === '' || $store === null) {
            return false;
        }

        $productIds = array_map(static fn (string|int $id): int => (int) $id, array_keys($cart['items']));
        $productsById = Product::query()
            ->where('store_id', $store->id)
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        $lines = ['Carrinho - '.$store->name];
        $total = 0.0;

        foreach ($cart['items'] as $productId => $qty) {
            $product = $productsById->get($productId);

            if (! $product instanceof Product || $qty < 1) {
                continue;
            }

            $lineTotal = ((float) $product->price) * (int) $qty;
            $total += $lineTotal;
            $lines[] = '- '.$qty.'x '.$product->name.' (R$ '.number_format($lineTotal, 2, ',', '.').')';
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
        $store = Store::query()->where('slug', $storeId)->first();

        if ($storeId === '' || $store === null) {
            return false;
        }

        $productIds = array_map(static fn (string|int $id): int => (int) $id, array_keys($cart['items']));
        $productsById = Product::query()
            ->where('store_id', $store->id)
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        $total = 0.0;

        foreach ($cart['items'] as $productId => $qty) {
            $product = $productsById->get($productId);

            if (! $product instanceof Product || $qty < 1) {
                continue;
            }

            $total += ((float) $product->price) * (int) $qty;
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
        $category = Category::query()
            ->where('is_active', true)
            ->where('slug', $categoryId)
            ->first();

        if ($category === null) {
            return false;
        }

        $stores = Store::query()
            ->where('is_active', true)
            ->where('category_id', $category->id)
            ->orderBy('name')
            ->limit(10)
            ->with('category:id,slug,name')
            ->get();

        return $this->sendStoreCarouselFromCollection($phone, $stores, $this->buildCategoryHeader($category));
    }

    private function sendCategoriesCarousel(string $phone): bool
    {
        $categories = Category::query()
            ->where('is_active', true)
            ->orderBy('ordem_exibicao')
            ->limit(10)
            ->get();

        if ($categories->isEmpty()) {
            return $this->sendAllStoresFallback($phone, 'Nenhuma categoria encontrada. Exibindo as lojas ativas.');
        }

        $cards = [];

        foreach ($categories as $category) {
            $cards[] = [
                'text' => $category->name,
                'image' => $category->image_url ?: 'https://picsum.photos/seed/categoria-default/600/600',
                'buttons' => [
                    [
                        'id' => 'buscar_cat_'.$this->buttonSlugFromCategorySlug((string) $category->slug),
                        'label' => 'Ver lojas',
                        'type' => 'REPLY',
                    ],
                ],
            ];
        }

        try {
            $this->zapiClient->sendCarousel($phone, 'Escolha uma categoria para ver lojas ativas.', $cards);

            return true;
        } catch (\Throwable $exception) {
            Log::warning('Failed to send categories carousel.', [
                'phone' => $phone,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
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

    private function sendStoreCarouselFromCollection(string $phone, $stores, string $message): bool
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

    private function buttonSlugFromCategorySlug(string $slug): string
    {
        if (str_starts_with($slug, 'cat_')) {
            return substr($slug, 4);
        }

        return $slug;
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

        foreach ($candidates as $candidate) {
            $id = $this->extractScalarText($candidate, ['selectedRowId', 'id']);

            if ($id === null) {
                continue;
            }

            $normalized = strtolower(trim($id));

            if (str_starts_with($normalized, 'buscar_cat_')) {
                $slug = substr($normalized, strlen('buscar_cat_'));

                return $this->resolveCategorySlugFromButtonSlug((string) $slug);
            }

            if (str_starts_with($normalized, 'cat_')) {
                return Category::query()->where('is_active', true)->where('slug', $normalized)->value('slug');
            }

            $prefixed = 'cat_'.$normalized;

            $prefixedExists = Category::query()->where('is_active', true)->where('slug', $prefixed)->value('slug');

            if (is_string($prefixedExists)) {
                return $prefixedExists;
            }
        }

        return null;
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

    private function searchStoreIdsByTags(array $tags): array
    {
        if ($tags === []) {
            return Store::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->pluck('slug')
                ->filter(fn (mixed $slug): bool => is_string($slug) && trim($slug) !== '')
                ->values()
                ->all();
        }

        $query = implode(' ', array_map(
            fn (mixed $tag): string => Str::of((string) $tag)->lower()->ascii()->toString(),
            $tags
        ));

        return $this->searchStoreIds($query);
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
