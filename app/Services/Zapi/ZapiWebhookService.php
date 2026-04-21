<?php

namespace App\Services\Zapi;

use App\Jobs\Whatsapp\SendCartFeedbackJob;
use App\Models\Category;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\Store;
use App\Models\UserAddress;
use App\Models\UserPhone;
use App\Models\User;
use App\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
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

        // Hard reset: apaga toda a sessão
        if ($normalizedText === 'limpar') {
            Cache::forget(self::FLOW_STATE_CACHE_PREFIX.$phone);

            try {
                $this->zapiClient->sendText(
                    $phone,
                    "🗑️ Sessão resetada com sucesso!\n\nDigite *oi* para começar de novo. 😄"
                );

                return true;
            } catch (\Throwable) {
                return false;
            }
        }

        // Checkout flow interception
        $checkoutStep = (string) ($state['checkout_step'] ?? '');
        if (in_array($checkoutStep, ['verify_email_lookup', 'verify_email_code', 'collect_name', 'collect_address', 'collect_reference', 'collect_email', 'change_address', 'confirm_data', 'checkout_summary'], true)) {
            return $this->handleCheckoutTextInput($phone, $messageText, $normalizedText, $checkoutStep);
        }

        // Mid-flow interception: pending variation choice (products with variations)
        $pending = $state['pending_add'] ?? null;
        if (is_array($pending) && ($pending['step'] ?? '') === 'variation') {
            // Variation is chosen via buttons; if customer types here, guide them
            try {
                $this->zapiClient->sendText($phone, '👆 Por favor, escolha uma das opções de variação disponíveis acima.');

                return true;
            } catch (\Throwable) {
                return false;
            }
        }

        if (($state['welcomed'] ?? false) !== true) {
            $state['welcomed'] = true;
            $this->saveFlowState($phone, $state);

            return $this->sendWelcomePrompt($phone);
        }

        // If a navigation keyword is typed while the observation window is open, close it.
        $isNavigationKeyword = in_array($normalizedText, [
            'carrinho', 'finalizar', 'checkout', 'pagar',
            'voltar', 'voltar lojas', 'trocar loja', 'outra loja',
            'oi', 'ola', 'oie', 'menu', 'inicio', 'start',
            'ver categorias', 'categorias', 'ver lojas', 'lojas', 'mostrar lojas',
        ], true);

        if ($isNavigationKeyword && isset($state['last_added_cart_index'])) {
            unset($state['last_added_cart_index']);
            $this->saveFlowState($phone, $state);
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

        // Observation window: if the customer just added an item and types free text,
        // save it as the observation for that item (one text message = one observation).
        $lastAddedIndex = $state['last_added_cart_index'] ?? null;
        if ($lastAddedIndex !== null && is_int($lastAddedIndex)) {
            $cart = $state['cart'] ?? ['items' => []];
            if (isset($cart['items'][$lastAddedIndex])) {
                $cart['items'][$lastAddedIndex]['observation'] = trim($messageText);
                $state['cart'] = $cart;
                unset($state['last_added_cart_index']);
                $this->saveFlowState($phone, $state);

                $productName = (string) ($cart['items'][$lastAddedIndex]['product_name'] ?? 'item');

                try {
                    $this->zapiClient->sendText(
                        $phone,
                        "📝 *Observação salva:* _{$messageText}_\nPara: *{$productName}*."
                    );

                    return true;
                } catch (\Throwable) {
                    return false;
                }
            }
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

        if (str_starts_with($buttonId, 'flow_variation_')) {
            return $this->handleVariationSelected($phone, substr($buttonId, strlen('flow_variation_')));
        }

        // ── New post-add action buttons ────────────────────────────────────
        if ($buttonId === 'flow_continue_shopping') {
            $state   = $this->flowState($phone);
            $storeId = (string) ($state['selected_store_id'] ?? '');
            unset($state['last_added_cart_index']);
            $this->saveFlowState($phone, $state);

            if ($storeId !== '') {
                return $this->sendProductsCarousel($phone, $storeId, 0);
            }

            return $this->returnToStores($phone);
        }

        if ($buttonId === 'flow_finalize_order') {
            $state = $this->flowState($phone);
            unset($state['last_added_cart_index']);
            $this->saveFlowState($phone, $state);

            return $this->finalizeCart($phone);
        }

        // ── Legacy quantity / observation buttons (backward-compat) ────────
        if ($buttonId === 'flow_obs_continue' || $buttonId === 'flow_skip_obs') {
            $state = $this->flowState($phone);
            $storeId = (string) ($state['selected_store_id'] ?? '');
            unset($state['last_added_cart_index']);
            $this->saveFlowState($phone, $state);

            if ($storeId !== '') {
                return $this->sendProductsCarousel($phone, $storeId, 0);
            }

            return $this->returnToStores($phone);
        }

        if ($buttonId === 'flow_obs_finalize') {
            return $this->finalizeCart($phone);
        }

        if ($buttonId === 'cart_keep_existing') {
            $state = $this->flowState($phone);
            unset($state['store_switch_intent']);
            $this->saveFlowState($phone, $state);

            try {
                $this->zapiClient->sendText(
                    $phone,
                    'Perfeito! Vamos continuar com o carrinho atual. 🛒'
                );
            } catch (\Throwable) {
            }

            return $this->sendCartSummary($phone);
        }

        if ($buttonId === 'cart_start_new') {
            $state = $this->flowState($phone);
            $intent = $state['store_switch_intent'] ?? null;

            if (! is_array($intent)) {
                return $this->sendCartSummary($phone);
            }

            $targetStoreId = (string) ($intent['target_store_id'] ?? '');
            $targetProductId = (string) ($intent['target_product_id'] ?? '');

            if ($targetStoreId === '' || $targetProductId === '') {
                unset($state['store_switch_intent']);
                $this->saveFlowState($phone, $state);

                return $this->sendCartSummary($phone);
            }

            $state['cart'] = ['store_id' => $targetStoreId, 'items' => []];
            $state['selected_store_id'] = $targetStoreId;
            unset($state['pending_add'], $state['store_switch_intent']);
            $this->saveFlowState($phone, $state);

            return $this->startAddProductFlow($phone, $targetStoreId, $targetProductId);
        }

        if ($buttonId === 'checkout_pay_now_from_cart') {
            return $this->finalizeCart($phone);
        }

        if ($buttonId === 'checkout_confirm_data') {
            return $this->sendOrderSummary($phone);
        }

        if ($buttonId === 'checkout_confirm_address') {
            $st = $this->flowState($phone);
            $st['checkout_step'] = '';
            $this->saveFlowState($phone, $st);

            return $this->sendOrderSummary($phone);
        }

        if ($buttonId === 'checkout_change_address') {
            $st = $this->flowState($phone);
            $st['checkout_step'] = 'change_address';
            $this->saveFlowState($phone, $st);

            try {
                $this->zapiClient->sendText(
                    $phone,
                    "📍 Claro! Digite seu novo endereço completo:\n_Ex: Rua das Flores, 123 – Centro, Cidade – UF_"
                );

                return true;
            } catch (\Throwable) {
                return false;
            }
        }

        if ($buttonId === 'checkout_skip_reference') {
            $st = $this->flowState($phone);
            $st['customer']['reference'] = null;
            $st['checkout_step'] = ! empty($st['customer']['email']) ? '' : 'collect_email';
            $this->saveFlowState($phone, $st);

            if (! empty($st['customer']['email'])) {
                return $this->sendDataConfirmation($phone);
            }

            try {
                $this->zapiClient->sendButtonActions($phone, "📧 Informe seu e-mail para receber o comprovante:\n_(opcional)_", [
                    ['id' => 'checkout_skip_email', 'label' => 'Pular'],
                ]);

                return true;
            } catch (\Throwable) {
                return false;
            }
        }

        if ($buttonId === 'checkout_skip_email') {
            $st = $this->flowState($phone);
            $st['customer']['email'] = null;
            $this->saveFlowState($phone, $st);

            return $this->sendDataConfirmation($phone);
        }

        if ($buttonId === 'checkout_pay_now') {
            return $this->processPayment($phone);
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
            return $this->startAddProductFlow($phone, $addPayload['store_id'], $addPayload['product_id'], (int) ($addPayload['quantity'] ?? 1));
        }

        return $this->handleCommerceReplyIntent(['buttonId' => $buttonId], $phone, $buttonId);
    }

    private function resolveAddButtonPayload(string $buttonId): ?array
    {
        // New format: flow_add{qty}_{store_id}_{product_id}  (qty = 1, 2 or 3 immediately after "flow_add")
        if (preg_match('/^flow_add([123])_([a-z0-9_\-]+)_(\d+)$/', $buttonId, $matches) === 1) {
            return [
                'store_id'  => $matches[2],
                'product_id' => $matches[3],
                'quantity'  => (int) $matches[1],
            ];
        }

        // Old/backward-compat format: flow_add_{store_id}_{product_id}
        if (preg_match('/^flow_add_([a-z0-9_\-]+)_(\d+)$/', $buttonId, $matches) === 1) {
            return [
                'store_id'  => $matches[1],
                'product_id' => $matches[2],
                'quantity'  => 1,
            ];
        }

        return null;
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

    private function buildStoreDeliveryFee(Store $store): float
    {
        return 8.00;
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
                    ['id' => 'flow_add1_'.$store->slug.'_'.(int) $product->id, 'label' => '➕ Adicionar 1', 'type' => 'REPLY'],
                    ['id' => 'flow_add2_'.$store->slug.'_'.(int) $product->id, 'label' => '➕ Adicionar 2', 'type' => 'REPLY'],
                    ['id' => 'flow_add3_'.$store->slug.'_'.(int) $product->id, 'label' => '➕ Adicionar 3', 'type' => 'REPLY'],
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

    // ──────────────────────────────────────────────────────────────────────────
    // Add-to-cart flow: variation (if applicable) → immediate commit
    // ──────────────────────────────────────────────────────────────────────────

    private function startAddProductFlow(string $phone, string $storeId, string $productId, int $quantity = 1): bool
    {
        $store = Store::query()
            ->where('is_active', true)
            ->where('slug', $storeId)
            ->first();

        if ($store === null) {
            return false;
        }

        $product = Product::query()
            ->with('variations')
            ->where('is_active', true)
            ->where('store_id', $store->id)
            ->where('id', (int) $productId)
            ->first();

        if ($product === null) {
            return false;
        }

        // Check for active variations
        $variations = $product->variations
            ->filter(fn (ProductVariation $v): bool => (bool) $v->is_active)
            ->values();

        if ($variations->isEmpty() || ! (bool) $product->has_variations) {
            // No variations: commit immediately with the chosen quantity
            return $this->commitAndSendFeedback($phone, $storeId, (int) $productId, null, null, 0.0, $quantity);
        }

        // Has variations: store quantity in pending_add and ask the customer to choose
        $state = $this->flowState($phone);

        $cart = $state['cart'] ?? ['store_id' => null, 'items' => []];
        if (($cart['store_id'] ?? null) !== null
            && $cart['store_id'] !== $storeId
            && is_array($cart['items'] ?? null)
            && $cart['items'] !== []) {
            return $this->sendCartStoreConflictPrompt($phone, (string) $cart['store_id'], $storeId, (int) $productId);
        }

        $state['pending_add'] = [
            'store_id'                   => $storeId,
            'product_id'                 => (int) $productId,
            'step'                       => 'variation',
            'variation_id'               => null,
            'variation_name'             => null,
            'variation_additional_price' => 0.0,
            'quantity'                   => $quantity,
        ];
        $this->saveFlowState($phone, $state);


        return $this->sendVariationPrompt($phone, $product, $variations);
    }

    private function sendVariationPrompt(string $phone, Product $product, $variations): bool
    {
        $question = trim((string) ($product->variation_question ?: 'Como você prefere?'));

        $buttons = $variations->take(3)->map(fn (ProductVariation $v): array => [
            'id'    => 'flow_variation_'.(int) $v->id,
            'label' => $v->name.(
                ((float) $v->additional_price) > 0
                    ? ' (+R$ '.number_format((float) $v->additional_price, 2, ',', '.').')'
                    : ''
            ),
        ])->values()->all();

        // >3 variations: use option list; ≤3: use button actions
        if ($variations->count() > 3) {
            $options = $variations->map(fn (ProductVariation $v): array => [
                'id'          => 'flow_variation_'.(int) $v->id,
                'title'       => $v->name,
                'description' => ((float) $v->additional_price) > 0
                    ? '+R$ '.number_format((float) $v->additional_price, 2, ',', '.')
                    : '',
            ])->values()->all();

            try {
                $this->zapiClient->sendList(
                    $phone,
                    $question,
                    'Ver opções',
                    $product->name,
                    '',
                    $options
                );

                return true;
            } catch (\Throwable $exception) {
                Log::warning('Failed to send variation list.', ['error' => $exception->getMessage()]);

                return false;
            }
        }

        try {
            $this->zapiClient->sendButtonActions($phone, $question, $buttons);

            return true;
        } catch (\Throwable $exception) {
            Log::warning('Failed to send variation buttons.', ['error' => $exception->getMessage()]);

            return false;
        }
    }

    private function handleVariationSelected(string $phone, string $variationId): bool
    {
        $state = $this->flowState($phone);
        $pending = $state['pending_add'] ?? null;

        if (! is_array($pending)) {
            return false;
        }

        $variation = ProductVariation::query()
            ->where('id', (int) $variationId)
            ->where('is_active', true)
            ->first();

        if ($variation === null) {
            return false;
        }

        $pending['variation_id']               = (int) $variationId;
        $pending['variation_name']             = (string) $variation->name;
        $pending['variation_additional_price'] = (float) $variation->additional_price;

        $quantity = (int) ($pending['quantity'] ?? 1);

        // Clear pending_add before committing so concurrent clicks are handled cleanly
        unset($state['pending_add']);
        $this->saveFlowState($phone, $state);

        return $this->commitAndSendFeedback(
            $phone,
            (string) ($pending['store_id'] ?? ''),
            (int) ($pending['product_id'] ?? 0),
            (int) $variationId,
            (string) $variation->name,
            (float) $variation->additional_price,
            $quantity
        );
    }

    /**
     * Add item to cart (with a per-phone lock to prevent concurrent writes) and
     * send a feedback message that includes a full cart summary and an observation hint.
     */
    private function commitAndSendFeedback(
        string $phone,
        string $storeId,
        int $productId,
        ?int $variationId,
        ?string $variationName,
        float $variationAdditionalPrice,
        int $quantity
    ): bool {
        $lock = Cache::lock('zapi:cart:lock:'.$phone, 10);

        $product = null;

        try {
            $lock->block(5);

            $store = Store::query()->where('is_active', true)->where('slug', $storeId)->first();
            if ($store === null) {
                return false;
            }

            $product = Product::query()
                ->where('is_active', true)
                ->where('store_id', $store->id)
                ->where('id', $productId)
                ->first();

            if ($product === null) {
                return false;
            }

            // Re-read state inside the lock so racing adds are serialised
            $state = $this->flowState($phone);
            $cart  = $state['cart'] ?? ['store_id' => null, 'items' => []];

            if (($cart['store_id'] ?? null) !== null
                && $cart['store_id'] !== $storeId
                && is_array($cart['items'] ?? null)
                && $cart['items'] !== []) {
                $lock->release();

                return $this->sendCartStoreConflictPrompt($phone, (string) $cart['store_id'], $storeId, $productId);
            }

            if (! isset($cart['items']) || ! array_is_list((array) $cart['items'])) {
                $cart['items'] = [];
            }

            $cart['store_id'] = $storeId;
            $cart['items'][]  = [
                'product_id'       => $productId,
                'product_name'     => (string) $product->name,
                'base_price'       => (float) $product->price,
                'variation_id'     => $variationId,
                'variation_name'   => $variationName,
                'additional_price' => $variationAdditionalPrice,
                'quantity'         => $quantity,
                'observation'      => null,
            ];

            $newItemIndex = count($cart['items']) - 1;

            $state['cart']                   = $cart;
            $state['selected_store_id']      = $storeId;
            $state['last_added_cart_index']  = $newItemIndex;
            unset($state['pending_add']);

            $this->saveFlowState($phone, $state);
        } catch (\Throwable $e) {
            Log::warning('commitAndSendFeedback: cart lock/write failed.', [
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);

            return false;
        } finally {
            optional($lock)->release();
        }

        // ── Debounce: increment nonce and schedule a delayed feedback ──────
        $nonceKey = 'zapi:feedback:nonce:'.$phone;
        $nonce    = (int) Cache::increment($nonceKey);
        Cache::put($nonceKey, $nonce, now()->addMinutes(5));

        SendCartFeedbackJob::dispatch($phone, $nonce)
            ->delay(now()->addSeconds(3));

        return true;
    }

    /**
     * Called by SendCartFeedbackJob after the debounce window.
     * Reads the current cart from state and sends one consolidated feedback message.
     */
    public function sendCartFeedbackNow(string $phone): void
    {
        $state = $this->flowState($phone);
        $cart  = $state['cart'] ?? ['store_id' => null, 'items' => []];

        $cartItems = $this->normalizeCartItems($cart['items'] ?? []);

        if ($cartItems === []) {
            return;
        }

        $summaryLines = [];
        $cartTotal    = 0.0;

        foreach ($cartItems as $item) {
            $itemLabel = $item['product_name'].($item['variation_name'] ? ' ('.$item['variation_name'].')' : '');
            $itemUnit  = ($item['base_price'] + $item['additional_price']);
            $itemTotal = $itemUnit * $item['quantity'];
            $cartTotal += $itemTotal;
            $summaryLines[] = '• '.$item['quantity'].'x *'.$itemLabel.'* — R$ '.number_format($itemTotal, 2, ',', '.');
        }

        $count = count($cartItems);
        $header = $count === 1
            ? '✅ *1 item* no carrinho'
            : "✅ *{$count} itens* no carrinho";

        $message = "{$header}\n"
            ."——————————————\n"
            ."🛒 *Seu carrinho:*\n"
            .implode("\n", $summaryLines)."\n"
            ."💰 *Total: R\$ ".number_format($cartTotal, 2, ',', '.')."*\n"
            ."——————————————\n"
            ."💬 Quer adicionar uma observação no último item?\n"
            ."_Ex: sem cebola, sem molho, bem passado…_\n"
            ."_Só digitar agora._\n\n"
            ."🛍️ Pode continuar escolhendo na lista de produtos acima.";

        try {
            $this->zapiClient->sendButtonActions($phone, $message, [
                ['id' => 'flow_finalize_order', 'label' => '✅ Finalizar pedido'],
            ]);
        } catch (\Throwable $exception) {
            Log::warning('sendCartFeedbackNow: failed to send feedback.', [
                'phone' => $phone,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function commitCartItem(
        string $phone,
        string $storeId,
        int $productId,
        ?int $variationId,
        ?string $variationName,
        float $variationAdditionalPrice,
        int $quantity,
        ?string $observation,
        bool $silent = false
    ): bool {
        $store = Store::query()->where('is_active', true)->where('slug', $storeId)->first();

        if ($store === null) {
            return false;
        }

        $product = Product::query()
            ->where('is_active', true)
            ->where('store_id', $store->id)
            ->where('id', $productId)
            ->first();

        if ($product === null) {
            return false;
        }

        $state = $this->flowState($phone);
        $cart = $state['cart'] ?? ['store_id' => null, 'items' => []];
        if (($cart['store_id'] ?? null) !== null
            && $cart['store_id'] !== $storeId
            && is_array($cart['items'] ?? null)
            && $cart['items'] !== []) {
            return $this->sendCartStoreConflictPrompt($phone, (string) $cart['store_id'], $storeId, $productId);
        }

        // Ensure items is an indexed array
        if (! isset($cart['items']) || ! array_is_list((array) $cart['items'])) {
            $cart['items'] = [];
        }

        $cart['store_id'] = $storeId;
        $cart['items'][]  = [
            'product_id'        => $productId,
            'product_name'      => (string) $product->name,
            'base_price'        => (float) $product->price,
            'variation_id'      => $variationId,
            'variation_name'    => $variationName,
            'additional_price'  => $variationAdditionalPrice,
            'quantity'          => $quantity,
            'observation'       => $observation,
        ];

        $state['cart']              = $cart;
        $state['selected_store_id'] = $storeId;
        unset($state['pending_add']);
        $this->saveFlowState($phone, $state);

        $label = $product->name.($variationName ? ' ('.$variationName.')' : '');
        $notice = '';

        if ($silent) {
            return true;
        }

        $unitPrice = ((float) $product->price) + $variationAdditionalPrice;
        $lineTotal = $unitPrice * $quantity;

        $message = $notice.'✅ *'.$label.'* adicionado ao carrinho!'
            ."\n📦 Adicionado ao carrinho: {$quantity}x {$product->name} no valor de R$ ".number_format($lineTotal, 2, ',', '.')
            .($observation ? "\n📝 Obs: *{$observation}*" : '')
            ."\n\nDigite *carrinho* para revisar, *finalizar* para pagar ou continue escolhendo!";

        try {
            $this->zapiClient->sendText($phone, $message);

            return true;
        } catch (\Throwable $exception) {
            Log::warning('Failed to send commit-cart response.', [
                'phone'      => $phone,
                'store_id'   => $storeId,
                'product_id' => $productId,
                'error'      => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private function sendCartStoreConflictPrompt(string $phone, string $currentStoreId, string $targetStoreId, int $targetProductId): bool
    {
        $state = $this->flowState($phone);

        $currentStoreName = (string) (Store::query()->where('slug', $currentStoreId)->value('name') ?? 'loja atual');
        $targetStoreName = (string) (Store::query()->where('slug', $targetStoreId)->value('name') ?? 'nova loja');

        $state['store_switch_intent'] = [
            'current_store_id' => $currentStoreId,
            'target_store_id' => $targetStoreId,
            'target_product_id' => (string) $targetProductId,
        ];
        unset($state['pending_add']);
        $this->saveFlowState($phone, $state);

        $message = "⚠️ Seu carrinho atual é da loja *{$currentStoreName}*.\n"
            ."Não é possível misturar produtos de lojas diferentes.\n\n"
            ."Você prefere:\n"
            ."• continuar com o carrinho atual\n"
            ."• ou montar um novo carrinho na loja *{$targetStoreName}*?";

        try {
            $this->zapiClient->sendButtonActions($phone, $message, [
                ['id' => 'cart_keep_existing', 'label' => '🛒 Seguir carrinho atual'],
                ['id' => 'cart_start_new', 'label' => '✨ Novo carrinho desta loja'],
            ]);

            return true;
        } catch (\Throwable $exception) {
            Log::warning('Failed to send store-conflict prompt.', [
                'phone' => $phone,
                'current_store_id' => $currentStoreId,
                'target_store_id' => $targetStoreId,
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
                $this->zapiClient->sendText($phone, 'Seu carrinho está vazio. Escolha uma loja e adicione produtos. 🛒');

                return true;
            } catch (\Throwable $exception) {
                Log::warning('Failed to send empty-cart response.', ['phone' => $phone, 'error' => $exception->getMessage()]);

                return false;
            }
        }

        $storeId = (string) ($cart['store_id'] ?? '');
        $store = Store::query()->where('slug', $storeId)->first();

        if ($storeId === '' || $store === null) {
            return false;
        }

        $lines = ['🛒 *Carrinho — '.$store->name.'*', ''];
        $total = 0.0;
        $index = 1;

        foreach ($this->normalizeCartItems($cart['items']) as $item) {
            $lineTotal = ($item['base_price'] + $item['additional_price']) * $item['quantity'];
            $total += $lineTotal;
            $label = $item['product_name'].($item['variation_name'] ? ' ('.$item['variation_name'].')' : '');
            $lines[] = $index.'. '.$item['quantity'].'x *'.$label.'* — R$ '.number_format($lineTotal, 2, ',', '.');
            if ($item['observation']) {
                $lines[] = '   📝 '.$item['observation'];
            }
            $index++;
        }

        $lines[] = '';
        $lines[] = '💰 *Total: R$ '.number_format($total, 2, ',', '.').'*';

        try {
            $this->zapiClient->sendButtonActions(
                $phone,
                implode("\n", $lines),
                [['id' => 'checkout_pay_now_from_cart', 'label' => '🛒 Finalizar pedido']]
            );

            return true;
        } catch (\Throwable $exception) {
            Log::warning('Failed to send cart summary.', ['phone' => $phone, 'error' => $exception->getMessage()]);

            return false;
        }
    }

    private function finalizeCart(string $phone): bool
    {
        $state = $this->flowState($phone);
        $cart = $state['cart'] ?? null;

        if (! is_array($cart) || ! is_array($cart['items'] ?? null) || $cart['items'] === []) {
            try {
                $this->zapiClient->sendText($phone, '🛒 Seu carrinho está vazio. Adicione produtos antes de finalizar.');

                return true;
            } catch (\Throwable $exception) {
                Log::warning('Failed to send finalize-empty-cart response.', ['phone' => $phone, 'error' => $exception->getMessage()]);

                return false;
            }
        }

        $normalizedPhone = $this->normalizePhoneForLookup($phone);
        $user = User::query()
            ->where('phone', $normalizedPhone)
            ->orWhereHas('phones', fn ($query) => $query->where('phone', $normalizedPhone))
            ->with(['primaryAddress'])
            ->first();

        if ($user !== null) {
            $customer = (array) ($state['customer'] ?? []);
            $customer['name'] = (string) ($customer['name'] ?? $user->name ?? '');
            $customer['email'] = (string) ($customer['email'] ?? $user->email ?? '');
            $customer['phone'] = $normalizedPhone;

            if (empty($customer['address'])) {
                if ($user->primaryAddress !== null) {
                    $customer['address'] = (string) ($user->primaryAddress->formatted ?? '');
                    $customer['reference'] = (string) ($user->primaryAddress->notes ?? '');
                }
            }

            $state['customer'] = $customer;
            $this->saveFlowState($phone, $state);

            if (! empty($customer['address'])) {
                return $this->sendAddressConfirmation($phone, $customer);
            }

            return $this->startCheckoutDataCollection($phone);
        }

        return $this->startEmailVerificationForNewNumber($phone);
    }

    private function startCheckoutDataCollection(string $phone): bool
    {
        $state = $this->flowState($phone);
        $hasName = ! empty((string) ($state['customer']['name'] ?? ''));
        $state['checkout_step'] = $hasName ? 'collect_address' : 'collect_name';
        $this->saveFlowState($phone, $state);

        try {
            if ($hasName) {
                $this->zapiClient->sendText(
                    $phone,
                    "📍 Perfeito! Agora informe o endereço completo de entrega:\n_Ex: Rua das Flores, 123 - Centro, Cidade - UF_"
                );
            } else {
                $this->zapiClient->sendText(
                    $phone,
                    "🛒 Ótima escolha! Vamos finalizar seu pedido.\n\n👤 Para começar, qual é o seu *nome completo*?"
                );
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function startEmailVerificationForNewNumber(string $phone): bool
    {
        $state = $this->flowState($phone);
        $state['checkout_step'] = 'verify_email_lookup';
        $state['customer']['phone'] = $this->normalizePhoneForLookup($phone);
        $this->saveFlowState($phone, $state);

        try {
            $this->zapiClient->sendText(
                $phone,
                "📧 Informe seu e-mail para verificarmos se já tem cadastro e receber o comprovante:"
            );

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function sendAddressConfirmation(string $phone, array $customer): bool
    {
        $state = $this->flowState($phone);
        $state['checkout_step'] = 'confirm_address';
        $this->saveFlowState($phone, $state);

        $name      = (string) ($customer['name'] ?? '');
        $address   = (string) ($customer['address'] ?? '');
        $reference = (string) ($customer['reference'] ?? '');

        $message = ($name ? "👋 Olá, *{$name}*!\n\n" : '')
            ."📍 *Entrega será em:*\n"
            .$address
            .($reference ? "\n📌 Referência: {$reference}" : '')
            ."\n\nEstá correto?";

        try {
            $this->zapiClient->sendButtonActions($phone, $message, [
                ['id' => 'checkout_confirm_address', 'label' => '✅ Confirmar endereço'],
                ['id' => 'checkout_change_address',  'label' => '✏️ Alterar endereço'],
            ]);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function handleCheckoutTextInput(string $phone, string $rawText, string $normalizedText, string $checkoutStep): bool
    {
        $state = $this->flowState($phone);

        // Allow escape words to cancel checkout
        if (in_array($normalizedText, ['cancelar', 'voltar', 'inicio', 'menu', 'limpar'], true)) {
            $state['checkout_step'] = '';
            $this->saveFlowState($phone, $state);

            return $this->sendWelcomePrompt($phone);
        }

        switch ($checkoutStep) {
            case 'verify_email_lookup':
                $email = strtolower(trim($rawText));

                if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    try {
                        $this->zapiClient->sendText($phone, '⚠️ E-mail inválido. Digite um e-mail válido para continuar.');

                        return true;
                    } catch (\Throwable) {
                        return false;
                    }
                }

                $normalizedPhone = $this->normalizePhoneForLookup($phone);
                $existingUser = User::query()->where('email', $email)->first();

                // Existing email: require code verification.
                if ($existingUser !== null) {
                    $code = (string) random_int(100000, 999999);
                    $state['customer']['email'] = $email;
                    $state['email_verification'] = [
                        'email' => $email,
                        'code_hash' => hash('sha256', $code),
                        'expires_at' => now()->addMinutes(10)->toIso8601String(),
                        'attempts' => 0,
                    ];
                    $state['checkout_step'] = 'verify_email_code';
                    $this->saveFlowState($phone, $state);

                    $this->sendEmailVerificationCode($email, $code);

                    try {
                        $this->zapiClient->sendText(
                            $phone,
                            '🔐 Enviamos um código de 6 dígitos para seu e-mail. Digite o código aqui no WhatsApp para confirmar.'
                        );

                        return true;
                    } catch (\Throwable) {
                        return false;
                    }
                }

                // New email: proceed without code and create minimal account.
                $fallbackName = trim(Str::before($email, '@'));
                if ($fallbackName === '') {
                    $fallbackName = 'Cliente WhatsApp';
                }

                $user = User::query()->create([
                    'name' => Str::title(str_replace(['.', '_', '-'], ' ', $fallbackName)),
                    'email' => $email,
                    'phone' => $normalizedPhone,
                    'email_verified_at' => now(),
                    'password' => Str::random(24),
                    'role' => 'customer',
                ]);

                $this->syncUserPhone($user, $normalizedPhone);

                $state['customer']['name'] = (string) ($state['customer']['name'] ?? $user->name ?? '');
                $state['customer']['email'] = $email;
                $state['customer']['phone'] = $normalizedPhone;
                unset($state['email_verification']);
                $state['checkout_step'] = 'collect_name';
                $this->saveFlowState($phone, $state);

                try {
                    $this->zapiClient->sendText(
                        $phone,
                        '✅ Cadastro iniciado com sucesso! Vamos seguir com os dados de entrega.'
                    );
                } catch (\Throwable) {
                }

                return $this->startCheckoutDataCollection($phone);

            case 'verify_email_code':
                $verification = $state['email_verification'] ?? null;

                if (! is_array($verification)) {
                    return $this->startEmailVerificationForNewNumber($phone);
                }

                $typedCode = preg_replace('/\D+/', '', $rawText) ?? '';
                $expiresAt = isset($verification['expires_at']) ? CarbonImmutable::parse((string) $verification['expires_at']) : null;

                if ($expiresAt === null || $expiresAt->isPast()) {
                    unset($state['email_verification']);
                    $state['checkout_step'] = 'verify_email_lookup';
                    $this->saveFlowState($phone, $state);

                    try {
                        $this->zapiClient->sendText($phone, '⌛ O código expirou. Informe seu e-mail novamente para enviar um novo código.');

                        return true;
                    } catch (\Throwable) {
                        return false;
                    }
                }

                if (hash('sha256', $typedCode) !== (string) ($verification['code_hash'] ?? '')) {
                    $verification['attempts'] = (int) ($verification['attempts'] ?? 0) + 1;
                    $state['email_verification'] = $verification;
                    $this->saveFlowState($phone, $state);

                    try {
                        $this->zapiClient->sendText($phone, '❌ Código inválido. Tente novamente.');

                        return true;
                    } catch (\Throwable) {
                        return false;
                    }
                }

                $email = strtolower(trim((string) ($verification['email'] ?? $state['customer']['email'] ?? '')));
                $normalizedPhone = $this->normalizePhoneForLookup($phone);

                $user = User::query()->where('email', $email)->first();

                if ($user !== null) {
                    if (empty((string) $user->phone)) {
                        $user->phone = $normalizedPhone;
                    }
                    if ($user->email_verified_at === null) {
                        $user->email_verified_at = now();
                    }
                    $user->save();
                    $this->syncUserPhone($user, $normalizedPhone);
                } else {
                    $fallbackName = trim(Str::before($email, '@'));
                    if ($fallbackName === '') {
                        $fallbackName = 'Cliente WhatsApp';
                    }

                    $user = User::query()->create([
                        'name' => Str::title(str_replace(['.', '_', '-'], ' ', $fallbackName)),
                        'email' => $email,
                        'phone' => $normalizedPhone,
                        'email_verified_at' => now(),
                        'password' => Str::random(24),
                        'role' => 'customer',
                    ]);

                    $this->syncUserPhone($user, $normalizedPhone);
                }

                $state['customer']['name'] = (string) ($state['customer']['name'] ?? $user->name ?? '');
                $state['customer']['email'] = $email;
                $state['customer']['phone'] = $normalizedPhone;
                unset($state['email_verification']);
                $state['checkout_step'] = 'collect_name';
                $this->saveFlowState($phone, $state);

                try {
                    $this->zapiClient->sendText(
                        $phone,
                        '✅ E-mail confirmado com sucesso! Vamos seguir com os dados de entrega.'
                    );
                } catch (\Throwable) {
                }

                return $this->startCheckoutDataCollection($phone);

            case 'collect_name':
                $state['customer']['name'] = trim($rawText);
                $state['checkout_step'] = 'collect_address';
                $this->saveFlowState($phone, $state);

                try {
                    $this->zapiClient->sendText(
                        $phone,
                        '📍 Perfeito, *'.trim($rawText).'*! Agora informe o endereço completo de entrega:'
                        ."\n_Ex: Rua das Flores, 123 – Centro, Cidade – UF_"
                    );

                    return true;
                } catch (\Throwable) {
                    return false;
                }

            case 'collect_address':
            case 'change_address':
                $state['customer']['address'] = trim($rawText);
                $state['checkout_step'] = $checkoutStep === 'change_address' ? '' : 'collect_reference';
                $this->saveFlowState($phone, $state);

                if ($checkoutStep === 'change_address') {
                    try {
                        $this->zapiClient->sendText($phone, '✅ Endereço atualizado!');
                    } catch (\Throwable) {
                    }

                    return $this->sendOrderSummary($phone);
                }

                try {
                    $this->zapiClient->sendButtonActions(
                        $phone,
                        "📍 Tem alguma referência para ajudar na entrega?\n_Ex: Próximo ao mercado, portão azul_",
                        [['id' => 'checkout_skip_reference', 'label' => 'Pular']]
                    );

                    return true;
                } catch (\Throwable) {
                    return false;
                }

            case 'collect_reference':
                $state['customer']['reference'] = trim($rawText);
                if (! empty((string) ($state['customer']['email'] ?? ''))) {
                    $state['checkout_step'] = '';
                    $this->saveFlowState($phone, $state);

                    return $this->sendDataConfirmation($phone);
                }

                $state['checkout_step'] = 'collect_email';
                $this->saveFlowState($phone, $state);

                try {
                    $this->zapiClient->sendButtonActions(
                        $phone,
                        "📧 Informe seu e-mail para receber o comprovante:\n_(opcional)_",
                        [['id' => 'checkout_skip_email', 'label' => 'Pular']]
                    );

                    return true;
                } catch (\Throwable) {
                    return false;
                }

            case 'collect_email':
                $state['customer']['email'] = trim($rawText);
                $this->saveFlowState($phone, $state);

                return $this->sendDataConfirmation($phone);

            case 'confirm_data':
            case 'checkout_summary':
                return $this->handleSummaryEdit($phone, $rawText, $normalizedText);
        }

        return false;
    }

    private function sendEmailVerificationCode(string $email, string $code): void
    {
        try {
            Mail::raw(
                "Seu código de verificação é: {$code}\n\nEsse código expira em 10 minutos.",
                static function ($message) use ($email): void {
                    $message->to($email)->subject('Código de verificação - Zapediu');
                }
            );
        } catch (\Throwable $exception) {
            Log::warning('Failed to send email verification code.', [
                'email' => $email,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function normalizePhoneForLookup(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? $phone;
    }

    private function resolveCustomerUser(?int $companyId, string $customerName, string $customerPhone, string $customerEmail, string $orderCode): ?User
    {
        if ($customerPhone === '' && $customerEmail === '') {
            return null;
        }

        $query = User::query();

        if ($companyId !== null) {
            $query->where('company_id', $companyId);
        }

        $query->where(function ($inner) use ($customerPhone, $customerEmail): void {
            if ($customerPhone !== '') {
                $inner->orWhere('phone', $customerPhone);
            }

            if ($customerEmail !== '') {
                $inner->orWhere('email', $customerEmail);
            }
        });

        $user = $query->first();

        if ($user !== null) {
            return $user;
        }

        return User::query()->create([
            'company_id' => $companyId,
            'name' => $customerName !== '' ? $customerName : 'Cliente '.($customerPhone !== '' ? $customerPhone : $orderCode),
            'email' => $customerEmail !== '' ? $customerEmail : 'cliente-'.($customerPhone !== '' ? $customerPhone : Str::lower(Str::slug($orderCode))).'@deliveryzap.local',
            'phone' => $customerPhone !== '' ? $customerPhone : null,
            'password' => Str::random(32),
            'is_admin' => false,
            'role' => 'customer',
        ]);
    }

    private function sendDataConfirmation(string $phone): bool
    {
        $state    = $this->flowState($phone);
        $customer = $state['customer'] ?? [];

        $name      = trim((string) ($customer['name']      ?? ''));
        $email     = trim((string) ($customer['email']     ?? ''));
        $address   = trim((string) ($customer['address']   ?? ''));
        $reference = trim((string) ($customer['reference'] ?? ''));

        $lines   = [];
        $lines[] = '✅ *Confirme seus dados de entrega:*';
        $lines[] = '';

        if ($name !== '')      { $lines[] = '👤 *Nome:* '.$name; }
        if ($email !== '')     { $lines[] = '📧 *E-mail:* '.$email; }
        if ($address !== '')   { $lines[] = '📍 *Endereço:* '.$address; }
        if ($reference !== '') { $lines[] = '📌 *Referência:* '.$reference; }

        $lines[] = '';
        $lines[] = '_Para corrigir algo, basta digitar:_';
        $lines[] = '_`nome: Novo Nome`, `endereco: Rua Nova, 456` ou `referencia: portão azul`_';

        $state['checkout_step'] = 'confirm_data';
        $this->saveFlowState($phone, $state);

        try {
            $this->zapiClient->sendButtonActions(
                $phone,
                implode("\n", $lines),
                [['id' => 'checkout_confirm_data', 'label' => '✅ Tudo certo']]
            );

            return true;
        } catch (\Throwable $exception) {
            Log::warning('Failed to send data confirmation.', ['phone' => $phone, 'error' => $exception->getMessage()]);

            return false;
        }
    }

    private function sendOrderSummary(string $phone): bool
    {
        $state   = $this->flowState($phone);
        $cart    = $state['cart'] ?? [];
        $storeId = (string) ($cart['store_id'] ?? '');
        $store   = Store::query()->where('slug', $storeId)->first();
        $customer = $state['customer'] ?? [];

        if ($store === null) {
            return false;
        }

        $items = $this->normalizeCartItems($cart['items'] ?? []);
        $subtotal = 0.0;
        $itemLines = [];

        foreach ($items as $item) {
            $lineTotal    = ($item['base_price'] + $item['additional_price']) * $item['quantity'];
            $subtotal    += $lineTotal;
            $label        = $item['product_name'].($item['variation_name'] ? ' ('.$item['variation_name'].')' : '');
            $line         = '• '.$item['quantity'].'x *'.$label.'* — R$ '.number_format($lineTotal, 2, ',', '.');
            if ($item['observation']) {
                $line .= "\n   📝 ".$item['observation'];
            }
            $itemLines[] = $line;
        }

        $deliveryFee = $this->buildStoreDeliveryFee($store);
        $total = $subtotal + $deliveryFee;

        $address   = (string) ($customer['address'] ?? '');
        $reference = (string) ($customer['reference'] ?? '');

        $etaSeeds = ['35–45 min', '30–40 min', '40–50 min', '45–55 min'];
        $eta      = $etaSeeds[abs(crc32((string) $storeId)) % count($etaSeeds)];

        $lines   = [];
        $lines[] = '🧾 *Resumo do seu pedido:*';
        $lines[] = '';

        foreach ($itemLines as $il) {
            $lines[] = $il;
        }

        $lines[] = '';
        $lines[] = '🧮 *Subtotal: R$ '.number_format($subtotal, 2, ',', '.').'*';
        $lines[] = '🚚 *Taxa de entrega:* '.($deliveryFee > 0 ? 'R$ '.number_format($deliveryFee, 2, ',', '.') : 'Grátis');
        $lines[] = '💰 *Total: R$ '.number_format($total, 2, ',', '.').'*';
        $lines[] = '';
        $lines[] = '📍 *Entrega em:*';
        $lines[] = $address ?: '—';

        if ($reference) {
            $lines[] = '📌 '.$reference;
        }

        $lines[] = '';
        $lines[] = '⏱️ *Tempo estimado:* '.$eta;
        $lines[] = '';
        $lines[] = '_Para alterar algo antes de pagar, basta digitar:_';
        $lines[] = '_`nome: Novo Nome`, `endereco: Rua Nova, 456` ou `referencia: portão azul`_';

        $state['checkout_step'] = 'checkout_summary';
        $this->saveFlowState($phone, $state);

        try {
            $this->zapiClient->sendButtonActions(
                $phone,
                implode("\n", $lines),
                [['id' => 'checkout_pay_now', 'label' => '💳 Pagar agora']]
            );

            return true;
        } catch (\Throwable $exception) {
            Log::warning('Failed to send order summary.', ['phone' => $phone, 'error' => $exception->getMessage()]);

            return false;
        }
    }

    private function handleSummaryEdit(string $phone, string $rawText, string $normalizedText): bool
    {
        $state = $this->flowState($phone);
        $patterns = [
            'name'      => '/^nome\s*:\s*(.+)$/iu',
            'address'   => '/^endere[cç]o\s*:\s*(.+)$/iu',
            'reference' => '/^refer[eê]ncia\s*:\s*(.+)$/iu',
            'email'     => '/^e?-?mail\s*:\s*(.+)$/iu',
        ];

        foreach ($patterns as $field => $pattern) {
            if (preg_match($pattern, trim($rawText), $matches) === 1) {
                $state['customer'][$field] = trim($matches[1]);
                $this->saveFlowState($phone, $state);

                return $this->sendOrderSummary($phone);
            }
        }

        try {
            $this->zapiClient->sendText(
                $phone,
                "💡 Para alterar dados, use o formato:\n`nome: Seu Nome`, `endereco: Rua Nova, 456`\n\nOu clique em *💳 Pagar agora* para confirmar."
            );

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function processPayment(string $phone): bool
    {
        $state    = $this->flowState($phone);
        $cart     = $state['cart'] ?? [];
        $storeId  = (string) ($cart['store_id'] ?? '');
        $customer = $state['customer'] ?? [];

        if ($storeId === '' || empty($cart['items'])) {
            try {
                $this->zapiClient->sendText($phone, '🛒 Seu carrinho está vazio. Adicione produtos para finalizar.');

                return true;
            } catch (\Throwable) {
                return false;
            }
        }

        $store = Store::query()->where('slug', $storeId)->first();
        $items = $this->normalizeCartItems($cart['items']);
        $subtotal = 0.0;

        foreach ($items as $item) {
            $subtotal += ($item['base_price'] + $item['additional_price']) * $item['quantity'];
        }

        $deliveryFee = $store instanceof Store ? $this->buildStoreDeliveryFee($store) : 0.0;
        $total = $subtotal + $deliveryFee;

        // Generate readable order code
        $orderCode = 'ZAP-'.date('ymd').'-'.strtoupper(Str::random(4));

        // Persist Order
        $customerUser = $this->resolveCustomerUser(
            $store?->company_id,
            (string) ($customer['name'] ?? ''),
            $this->normalizePhoneForLookup($phone),
            (string) ($customer['email'] ?? ''),
            $orderCode,
        );

        Log::info('testando se passa aqui ');

        // Gera token público de checkout
        $publicToken = \Str::random(32);
        $rawPayload = ['cart' => $cart, 'customer' => $customer, 'checkout' => ['public_token' => $publicToken]];

        Log::info('Creating order with code '.$orderCode, ['store' => $store?->toArray()]);

        $order = Order::query()->create([
            'code'             => $orderCode,
            'user_id'          => $customerUser?->id,
            'company_id'       => $store?->company_id,
            'store_id'         => $store?->id,
            'product_ids'      => array_values(array_map(static fn (array $item): int => (int) $item['product_id'], $items)),
            'status'           => 'pending',
            'payment_status'   => 'pending',
            'notes'            => (string) ($customer['reference'] ?? ''),
            'subtotal'         => $subtotal,
            'delivery_fee'     => $deliveryFee,
            'total'            => $total,
            'ordered_at'       => now(),
            'raw_payload'      => $rawPayload,
        ]);

        $this->syncUserPhone($customerUser, $this->normalizePhoneForLookup($phone));
        $this->syncUserAddress(
            $customerUser,
            (string) ($customer['address'] ?? ''),
            (string) ($customer['reference'] ?? '')
        );

        $paymentLink = $this->buildPaymentLink($phone, $storeId, $cart['items'], $total, $orderCode);
        $amount      = 'R$ '.number_format($total, 2, ',', '.');

        // Build message body
        $msgLines   = [];
        $msgLines[] = '🧾 *Nº DO PEDIDO:*';
        $msgLines[] = '`'.$orderCode.'`';
        $msgLines[] = '';

        foreach ($items as $item) {
            $label      = $item['product_name'].($item['variation_name'] ? ' ('.$item['variation_name'].')' : '');
            $lineTotal  = ($item['base_price'] + $item['additional_price']) * $item['quantity'];
            $msgLines[] = '• '.$item['quantity'].'x *'.$label.'* — R$ '.number_format($lineTotal, 2, ',', '.');
            if (! empty($item['observation'])) {
                $msgLines[] = '   📝 '.$item['observation'];
            }
        }

        $msgLines[] = '';
        $msgLines[] = '🧮 *Subtotal: R$ '.number_format($subtotal, 2, ',', '.').'*';
        $msgLines[] = '🚚 *Taxa de entrega:* '.($deliveryFee > 0 ? 'R$ '.number_format($deliveryFee, 2, ',', '.') : 'Grátis');
        $msgLines[] = '💰 *Total: '.$amount.'*';
        $msgLines[] = '';
        $msgLines[] = '💳 Aceitamos *PIX e cartão*';
        $msgLines[] = '_Após o pagamento, você receberá a confirmação aqui mesmo. 🙏_';

        try {
            $this->zapiClient->sendButtonActions(
                $phone,
                implode("\n", $msgLines),
                [['type' => 'URL', 'url' => $paymentLink, 'label' => '🔗 Abrir link de pagamento']]
            );

            // Clear cart, persist order reference in state
            $state['last_order_code']      = $orderCode;
            $state['last_order_id']        = $order->id;
            $state['last_checkout_amount'] = $total;
            $state['last_checkout_at']     = now()->toIso8601String();
            $state['last_payment_link']    = $paymentLink;
            $state['cart']                 = ['store_id' => $storeId, 'items' => []];
            $state['checkout_step']        = '';
            $this->saveFlowState($phone, $state);

            return true;
        } catch (\Throwable $exception) {
            Log::warning('Failed to send payment link.', [
                'phone' => $phone,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Normalises cart items to a consistent array of objects regardless of whether
     * they were stored in the old format (assoc keyed by product_id => qty)
     * or the new format (indexed list of item arrays).
     *
     * @return array<int, array{product_id: int, product_name: string, base_price: float, additional_price: float, quantity: int, variation_id: int|null, variation_name: string|null, observation: string|null}>
     */
    private function normalizeCartItems(array $items): array
    {
        if (array_is_list($items)) {
            // New format: each element is an item array
            return array_values(array_filter(
                array_map(fn (mixed $item): ?array => is_array($item) ? [
                    'product_id'       => (int) ($item['product_id'] ?? 0),
                    'product_name'     => (string) ($item['product_name'] ?? 'Produto'),
                    'base_price'       => (float) ($item['base_price'] ?? 0.0),
                    'additional_price' => (float) ($item['additional_price'] ?? 0.0),
                    'quantity'         => max(1, (int) ($item['quantity'] ?? 1)),
                    'variation_id'     => isset($item['variation_id']) ? (int) $item['variation_id'] : null,
                    'variation_name'   => isset($item['variation_name']) ? (string) $item['variation_name'] : null,
                    'observation'      => isset($item['observation']) && $item['observation'] !== '' ? (string) $item['observation'] : null,
                ] : null, $items)
            ));
        }

        // Old format: keyed by product_id => quantity (integer)
        $normalized = [];

        foreach ($items as $productId => $qty) {
            if ((int) $qty < 1) {
                continue;
            }

            $product = Product::find((int) $productId);

            $normalized[] = [
                'product_id'       => (int) $productId,
                'product_name'     => $product ? (string) $product->name : 'Produto #'.$productId,
                'base_price'       => $product ? (float) $product->price : 0.0,
                'additional_price' => 0.0,
                'quantity'         => (int) $qty,
                'variation_id'     => null,
                'variation_name'   => null,
                'observation'      => null,
            ];
        }

        return $normalized;
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

        $alwaysShowMoreCard = true;
        $limit = 9;

        $stores = Store::query()
            ->where('is_active', true)
            ->where('category_id', $category->id)
            ->orderBy('name')
            ->limit($limit)
            ->with('category:id,slug,name')
            ->get();

        return $this->sendStoreCarouselFromCollection($phone, $stores, $this->buildCategoryHeader($category), $alwaysShowMoreCard);
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
            $this->zapiClient->sendCarousel($phone, 'Escolha uma categoria para ver as lojas disponíveis.', $cards);

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

    private function buildPaymentLink(string $phone, string $storeId, array $items, float $total, ?string $orderCode = null): string
    {
        $base = trim((string) config('services.zapi.payment_base_url', 'http://localhost:5173/checkout'));
        if ($base === '') {
            $base = 'http://localhost:5173/checkout';
        }

        // Busca o pedido pelo código
        $order = null;
        if ($orderCode) {
            $order = \App\Models\Order::where('code', $orderCode)->first();
        }

        // Recupera o token público salvo no raw_payload
        $token = '';
        if ($order && is_array($order->raw_payload) && isset($order->raw_payload['checkout']['public_token'])) {
            $token = $order->raw_payload['checkout']['public_token'];
        } else {
            $token = \Str::random(32);
        }

        // Monta o link amigável
        $orderCodePath = $orderCode ?? \Str::ulid()->toBase32();
        return rtrim($base, '/') . '/' . $orderCodePath . '?token=' . $token;
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
            ?? 'pending';

        $status = $this->mapIncomingOrderStatus(
            strtolower($this->extractScalarText($statusValue, ['status', 'text']) ?? 'pending')
        );

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

    private function mapIncomingOrderStatus(string $status): string
    {
        return match ($status) {
            'new' => 'pending',
            'confirmed' => 'accepted',
            'out_for_delivery' => 'delivering',
            'delivered' => 'done',
            'pending', 'accepted', 'preparing', 'delivering', 'done', 'cancelled' => $status,
            default => 'pending',
        };
    }

    private function syncUserPhone(?User $user, string $phone): void
    {
        if ($user === null || $phone === '') {
            return;
        }

        UserPhone::query()->where('user_id', $user->id)->update(['is_primary' => false]);

        UserPhone::query()->updateOrCreate(
            ['user_id' => $user->id, 'phone' => $phone],
            ['label' => 'principal', 'is_primary' => true]
        );
    }

    private function syncUserAddress(?User $user, string $formattedAddress, ?string $reference): void
    {
        if ($user === null || $formattedAddress === '') {
            return;
        }

        UserAddress::query()->where('user_id', $user->id)->update(['is_primary' => false]);

        UserAddress::query()->updateOrCreate(
            ['user_id' => $user->id, 'formatted' => $formattedAddress],
            [
                'street' => $formattedAddress,
                'notes' => $reference,
                'is_primary' => true,
            ]
        );
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
