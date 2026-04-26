<?php

namespace App\Services\Zapi\Flows;

// 1. Imports corrigidos (Problemas 3 e 4)
use App\Models\Store;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\User;
use App\Models\UserPhone;
use App\Models\UserAddress;
use App\Jobs\Whatsapp\SendCartFeedbackJob; // Importando o Job
use App\Services\Zapi\ZapiClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Services\Zapi\Handlers\StoreHandle;

class CartFlow
{
    // Propriedades definidas (Problema 1)
    public function __construct(
        private FlowManager $flow,
        private ZapiClient $zapiClient,
        private StoreHandle $storeHandle
    ) {
    }

    private function saveFlowState(string $phone, array $state): void
    {
        $this->flow->saveState($phone, $state);
    }
    public function sendCartStoreConflictPrompt(string $phone, string $oldStoreSlug, string $newStoreSlug, int $productId): bool
    {
        $message = "⚠️ *Atenção!* Você já tem itens de outra loja no carrinho.\n\nDeseja esvaziar o carrinho atual para adicionar este novo produto?";
        $actions = [
            ['id' => "cart_clear_and_add_{$productId}", 'label' => '🗑️ Esvaziar e Adicionar'],
            ['id' => 'flow_cart', 'label' => '🛒 Ver Carrinho Atual']
        ];

        $this->zapiClient->sendButtonActions($phone, $message, $actions);
        return true;
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
    private function getState(string $phone): array
    {
        return $this->flow->getState($phone);
    }

    public function startAddProductFlow(string $phone, string $storeId, string $productId, int $quantity = 1): bool
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
        $state = $this->getState($phone);

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

    public function handleVariationSelected(string $phone, string $variationId): bool
    {
        $state = $this->getState($phone);
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

    public function removeItem(string $phone, int $index): bool
    {
        $state = $this->flow->getState($phone);
        $cart = $state['cart'] ?? ['items' => []];

        // Verifica se o item realmente existe (evita erro se o usuário clicar duas vezes no mesmo botão)
        if (!isset($cart['items'][$index])) {
            return true;
        }

        // Guarda o nome para o feedback
        $removedItem = $cart['items'][$index];
        $itemName = $removedItem['product_name'];

        // Remove do array e reordena os índices (Importante!)
        unset($cart['items'][$index]);
        $cart['items'] = array_values($cart['items']);

        // Salva o novo estado
        $state['cart'] = $cart;
        $this->flow->saveState($phone, $state);

        // Feedback visual (Dispara a mensagem de texto)
        $this->zapiClient->sendText($phone, "🗑️ *{$itemName}* removido do seu carrinho.");

        // Se esvaziou, avisa e volta pro cardápio
        if (empty($cart['items'])) {
            $this->zapiClient->sendText($phone, "Seu carrinho agora está vazio.");
            // Opcional: chamar this->returnToMenu($phone);
            return true;
        }

        // Se ainda tem itens, envia o Resumo Atualizado
        return $this->sendCartSummary($phone);
    }
    public function handleAddMoreItems(string $phone): bool
    {
        $state = $this->flow->getState($phone);

        // Tenta pegar o store_id do carrinho ou do estado selecionado
        $storeSlug = $state['cart']['store_id'] ?? $state['selected_store_id'] ?? null;

        if ($storeSlug) {
            // Envia uma mensagem curta de contexto para o usuário não se perder
            $this->zapiClient->sendText($phone, "Certo! Olhe o cardápio aqui embaixo e escolha o que deseja adicionar: 👇");

            // Dispara o carrossel de produtos novamente
            // Note: use o método que você já tem no seu StoreHandle
            return $this->storeHandle->sendProductsCarousel($phone, $storeSlug, 0);
        }

        // Fallback: Se por algum motivo o estado sumiu, manda escolher a loja
        return $this->zapiClient->sendText($phone, "Para adicionar itens, escolha uma loja primeiro digitando *Cardápio*.");
    }

    public function sendEditCartCarousel(string $phone): bool
    {
        $state = $this->flow->getState($phone);
        $items = $this->normalizeCartItems($state['cart']['items'] ?? []);

        if (empty($items)) {
            $this->zapiClient->sendText($phone, 'Seu carrinho já está vazio.');
            return true;
        }

        $cards = [];

        foreach ($items as $index => $item) {
            $label = $item['product_name'] . ($item['variation_name'] ? " ({$item['variation_name']})" : "");
            $valorTotalItem = ($item['base_price'] + $item['additional_price']) * $item['quantity'];

            // 🔍 1. Busca o produto no banco de dados para pegar a foto real
            $product = \App\Models\Product::find($item['product_id']);

            // 🖼️ 2. Regra da Imagem: Se existir, usa a do banco. Se não, usa o padrão.
            // Nota: Substitua "image_path" pelo nome correto da sua coluna, se for diferente.
            $imageUrl = ($product && !empty($product->image_path))
                        ? $product->image_path
                        : 'https://picsum.photos/seed/padrao-'.$item['product_id'].'/600/600';

            $cards[] = [
                'text' => "*{$item['quantity']}x {$label}*\nValor: R$ " . number_format($valorTotalItem, 2, ',', '.'),
                'image' => $imageUrl, // <--- Aplicamos a imagem aqui!
                'buttons' => [
                    [
                        'id' => "cart_remove_{$index}",
                        'label' => '❌ Remover Item',
                        'type' => 'REPLY'
                    ]
                ]
            ];

            // Limite do WhatsApp (máx 10 cards)
            if (count($cards) >= 9) {
                break;
            }
        }

        // Card Fixo de "Adicionar Mais" no final
        $cards[] = [
            'text' => '*Esqueceu algo?*\nVocê pode voltar ao cardápio e adicionar mais itens.',
            'image' => 'https://picsum.photos/seed/add-more/600/600', // Imagem padrão do botão final
            'buttons' => [
                [
                    'id' => 'cart_add_more',
                    'label' => '➕ Adicionar mais',
                    'type' => 'REPLY'
                ]
            ]
        ];

        try {
            $this->zapiClient->sendCarousel($phone, "✏️ *Modo de Edição:*", $cards);
            return true;
        } catch (\Throwable $e) {
            Log::error('Erro ao enviar carrossel de edição: ' . $e->getMessage());
            return false;
        }
    }

    private function commitAndSendFeedback(
        string $phone,
        string $storeId,
        int $productId,
        ?int $variationId,
        ?string $variationName,
        float $variationAdditionalPrice,
        int $quantity
    ): bool {
        $lock = Cache::lock('zapi:cart:lock:' . $phone, 10);

        try {
            $lock->block(5);

            // 1. BUSCA LOJA E PRODUTO
            $store = Store::query()->where('is_active', true)->where('slug', $storeId)->first();
            if (!$store) {
                return false;
            }

            $product = Product::query()
                ->where('is_active', true)
                ->where('store_id', $store->id)
                ->where('id', $productId)
                ->first();

            if (!$product) {
                return false;
            }

            // 2. ATUALIZA O ESTADO DO CARRINHO
            $state = $this->flow->getState($phone);
            $cart  = $state['cart'] ?? ['store_id' => null, 'items' => []];

            // Validação de troca de loja
            if (($cart['store_id'] ?? null) !== null && $cart['store_id'] !== $storeId && !empty($cart['items'])) {
                $lock->release();
                return $this->sendCartStoreConflictPrompt($phone, (string) $cart['store_id'], $storeId, $productId);
            }

            // Adiciona o item ao array do carrinho
            $cart['store_id'] = $storeId;
            $cart['items'][] = [
                'product_id'       => $productId,
                'product_name'     => (string) $product->name,
                'base_price'       => (float) $product->price,
                'variation_id'     => $variationId,
                'variation_name'   => $variationName,
                'additional_price' => $variationAdditionalPrice,
                'quantity'         => $quantity,
                'observation'      => null,
            ];

            $state['cart'] = $cart;
            $state['selected_store_id'] = $storeId;
            unset($state['pending_add']);

            $this->saveFlowState($phone, $state);

            // --- LÓGICA DE AGRUPAMENTO (DEBOUNCE) ---

            // 3. GERA O NONCE (Marcador de versão para o Job)
            // Isso é o que o seu SendCartFeedbackJob espera como 2º argumento!
            $nonce = now()->getTimestampMs();
            \Illuminate\Support\Facades\Cache::put('zapi:feedback:nonce:' . $phone, $nonce, 60);

            // 4. DESPACHA O JOB COM DELAY
            // Passamos o telefone e o nonce. O Job só vai enviar a mensagem se o nonce bater.
            \App\Jobs\Whatsapp\SendCartFeedbackJob::dispatch($phone, $nonce)
                ->delay(now()->addSeconds(3));

            return true;

        } catch (\Throwable $e) {
            Log::warning('Erro ao salvar no carrinho: ' . $e->getMessage());
            return false;
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * Called by SendCartFeedbackJob after the debounce window.
     * Reads the current cart from state and sends one consolidated feedback message.
     */
    public function sendCartFeedbackNow(string $phone): void
    {
        $state = $this->flow->getState($phone);
        $cart  = $state['cart'] ?? ['store_id' => null, 'items' => []];
        $cartItems = $this->normalizeCartItems($cart['items'] ?? []);

        if ($cartItems === []) {
            return;
        }

        $summaryLines = [];
        $cartTotal = 0.0;

        // Aqui está o segredo: listamos todos os itens do carrinho atual
        foreach ($cartItems as $item) {
            $itemLabel = $item['product_name'].($item['variation_name'] ? ' ('.$item['variation_name'].')' : '');
            $itemUnit  = ($item['base_price'] + $item['additional_price']);
            $itemTotal = $itemUnit * $item['quantity'];
            $cartTotal += $itemTotal;
            $summaryLines[] = "- {$item['quantity']}x {$itemLabel}";
        }

        // Montamos a mensagem acumulada
        $message = "✅ *Itens adicionados:*\n\n"
            . implode("\n", $summaryLines)
            . "\n\n🛒 *Total parcial: R$ " . number_format($cartTotal, 2, ',', '.') . "*"
            . "\n\n*(Dica: você pode continuar escolhendo no cardápio acima.)*";

        try {
            $this->zapiClient->sendButtonActions($phone, $message, [
                ['id' => 'flow_finalize_order', 'label' => '🛒 Finalizar Pedido'],
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Feedback acumulado falhou.', ['error' => $exception->getMessage()]);
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

        $state = $this->flow->getState($phone);
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


    public function sendCartSummary(string $phone): bool
    {
        $state = $this->flow->getState($phone);
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
}
