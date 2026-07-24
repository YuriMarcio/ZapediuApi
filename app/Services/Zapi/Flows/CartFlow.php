<?php

namespace App\Services\Zapi\Flows;

// 1. Imports corrigidos (Problemas 3 e 4)
use App\Models\Store;
use App\Models\StorePizzaSize;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\OptionalFlow;
use App\Models\OptionalFlowStep;
use App\Models\OptionalFlowStepOption;
use App\Models\User;
use App\Models\UserPhone;
use App\Models\UserAddress;
use App\Jobs\Whatsapp\AdvanceCustomizationStepJob;
use App\Jobs\Whatsapp\SendCartFeedbackJob; // Importando o Job
use App\Services\Pizzaria\PizzaPricingResolver;
use App\Services\Whatsapp\WhatsAppClientInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Services\Zapi\Handlers\StoreHandle;

class CartFlow
{
    // Propriedades definidas (Problema 1)
    public function __construct(
        private FlowManager $flow,
        private WhatsAppClientInterface $zapiClient,
        private StoreHandle $storeHandle,
        private PizzaPricingResolver $pizzaPricing
    ) {
    }

    private function saveFlowState(string $phone, array $state): void
    {
        $this->flow->saveState($phone, $state);
    }
    /**
     * §10 — regra mono-loja, com os nomes das duas lojas dinâmicos (a que já está no
     * carrinho e a do produto que o cliente está tentando adicionar).
     */
    public function sendCartStoreConflictPrompt(string $phone, string $oldStoreSlug, string $newStoreSlug, int $productId): bool
    {
        $oldStoreName = Store::query()->where('slug', $oldStoreSlug)->value('name');
        $oldName = $oldStoreName ?? 'outra loja';
        $newName = Store::query()->where('slug', $newStoreSlug)->value('name') ?? 'essa loja';

        $message = "⚠️ *Atenção!* Seu carrinho já tem itens da {$oldName}.\n\n"
            ."Pra adicionar produtos da {$newName}, é preciso esvaziar o carrinho atual — só entregamos pedidos de uma loja por vez.";
        $actions = [
            ['id' => "cart_clear_and_add_{$productId}", 'label' => '🗑️ Esvaziar e Adicionar'],
            ['id' => 'flow_cart', 'label' => '🛒 Ver Carrinho Atual']
        ];

        $this->zapiClient->sendButtonActions($phone, $message, $actions, $this->brandedTitle($oldStoreName));
        return true;
    }

    /**
     * Header padrão de toda mensagem de botão do bot: "Zapediu" sozinho fora de contexto de
     * loja (ainda não sabe qual), ou "Zapediu & {Loja}" assim que dá pra saber — o carrinho é
     * mono-loja (§10), então basta o nome da loja atual da sessão pra identificar sem
     * ambiguidade qualquer mensagem do fluxo de compra.
     */
    private function brandedTitle(?string $storeName): string
    {
        $storeName = trim((string) $storeName);

        return $storeName !== '' ? 'Zapediu & '.$storeName : 'Zapediu';
    }

    private function storeNameBySlug(?string $storeSlug): ?string
    {
        if ($storeSlug === null || $storeSlug === '') {
            return null;
        }

        return Store::query()->where('slug', $storeSlug)->value('name');
    }

    private function sendVariationPrompt(string $phone, Product $product, $variations, ?string $storeName = null): bool
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

        // Botões WhatsApp: limite de 3 por mensagem — variações além da 3ª ficam de fora.
        try {
            $this->zapiClient->sendButtonActions($phone, $question, $buttons, $this->brandedTitle($storeName));

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
            ->available()
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
            // No variations: go through the customization engine (adicionais/etc.), which
            // commits immediately if the product has no OptionalFlow steps assigned.
            return $this->beginCustomizationOrCommit($phone, $storeId, (int) $productId, null, null, 0.0, $quantity);
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


        return $this->sendVariationPrompt($phone, $product, $variations, $store->name);
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

        return $this->routeAfterSizeChosen(
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
     * Entrada do fluxo de pizzaria/açaiteria: botão de tamanho tocado direto no carrossel de
     * produtos (ver ProductsHandler::buildProductButtons). Ao contrário de
     * startAddProductFlow, não depende de um pending_add pré-existente — o botão já carrega
     * produto + variação.
     */
    public function handlePizzaSizePicked(string $phone, string $productId, string $variationId): bool
    {
        $product = Product::query()
            ->where('is_active', true)
            ->where('id', (int) $productId)
            ->first();

        if ($product === null) {
            return false;
        }

        $variation = ProductVariation::query()
            ->where('id', (int) $variationId)
            ->where('product_id', $product->id)
            ->where('is_active', true)
            ->first();

        if ($variation === null) {
            return false;
        }

        $store = Store::query()->where('is_active', true)->where('id', $product->store_id)->first();

        if ($store === null) {
            return false;
        }

        $cart = $this->getState($phone)['cart'] ?? ['store_id' => null, 'items' => []];
        if (($cart['store_id'] ?? null) !== null
            && $cart['store_id'] !== $store->slug
            && is_array($cart['items'] ?? null)
            && $cart['items'] !== []) {
            return $this->sendCartStoreConflictPrompt($phone, (string) $cart['store_id'], $store->slug, $product->id);
        }

        return $this->routeAfterSizeChosen(
            $phone,
            $store->slug,
            $product->id,
            $variation->id,
            (string) $variation->name,
            (float) $variation->additional_price,
            1
        );
    }

    /**
     * Depois de um tamanho escolhido (por qualquer um dos dois caminhos acima): em lojas que
     * permitem combinar sabores e têm outros sabores ativos na mesma categoria, pergunta se
     * quer meio a meio antes de seguir pra customização/borda. Fora isso, comporta-se como
     * sempre.
     */
    private function routeAfterSizeChosen(
        string $phone,
        string $storeId,
        int $productId,
        int $variationId,
        string $variationName,
        float $variationAdditionalPrice,
        int $quantity
    ): bool {
        $store = Store::query()->where('slug', $storeId)->first();
        $product = Product::query()->where('id', $productId)->first();

        // Lojas pizzaria (motor avançado): o limite de sabores é do TAMANHO escolhido, não
        // mais um número único por loja. Açaiteria/outros seguem exatamente como antes
        // (usesFlavorComboFlow() lê store.max_flavors, inalterado).
        $isPizzaAdvanced = $store !== null && $store->isPizzaAdvancedStore();
        $sizeMaxFlavors = 2;
        $storePizzaSizeId = null;

        if ($isPizzaAdvanced) {
            $variation = ProductVariation::find($variationId);
            $sizeModel = $variation?->store_pizza_size_id !== null
                ? StorePizzaSize::find($variation->store_pizza_size_id)
                : null;

            if ($sizeModel !== null) {
                $storePizzaSizeId = $sizeModel->id;
                $sizeMaxFlavors = (int) $sizeModel->max_flavors;
            }
        }

        $allowsCombo = $isPizzaAdvanced ? $sizeMaxFlavors >= 2 : ($store !== null && $store->usesFlavorComboFlow());

        if ($store !== null && $product !== null && $allowsCombo && $product->available_for_combo) {
            $hasOtherFlavors = $product->category_id !== null && Product::query()
                ->where('is_active', true)
                ->where('store_id', $store->id)
                ->where('category_id', $product->category_id)
                ->where('id', '!=', $product->id)
                ->where('available_for_combo', true)
                ->exists();

            if ($hasOtherFlavors) {
                $firstFlavorPrice = $isPizzaAdvanced && $storePizzaSizeId !== null
                    ? ($this->pizzaPricing->resolveFlavorPrice($product, StorePizzaSize::find($storePizzaSizeId))['price'] ?? 0.0)
                    : ((float) $product->price + $variationAdditionalPrice);

                $state = $this->getState($phone);
                $state['pending_add'] = [
                    'store_id'                   => $storeId,
                    'product_id'                 => $productId,
                    'variation_id'               => $variationId,
                    'variation_name'             => $variationName,
                    'variation_additional_price' => $variationAdditionalPrice,
                    'quantity'                   => $quantity,
                    'step'                       => 'extra_flavor_ask',
                    'store_pizza_size_id'        => $storePizzaSizeId,
                    'max_flavors'                => $sizeMaxFlavors,
                    'flavors'                    => [
                        ['product_id' => $productId, 'name' => $product->name, 'price' => $firstFlavorPrice],
                    ],
                ];
                $this->saveFlowState($phone, $state);

                $flavorLabel = $product->name.' ('.$variationName.')';
                $prompt = $isPizzaAdvanced && $sizeMaxFlavors > 2
                    ? "🍕 *{$flavorLabel}*\nDeseja adicionar mais algum sabor nessa pizza? (1 de {$sizeMaxFlavors})"
                    : "🍕 *{$flavorLabel}*\nDeseja adicionar mais algum sabor nessa pizza?";

                try {
                    $this->zapiClient->sendButtonActions(
                        $phone,
                        $prompt,
                        [
                            ['id' => 'flow_pizza_extra_yes', 'label' => '✅ Sim'],
                            ['id' => 'flow_pizza_extra_no', 'label' => '❌ Não'],
                        ],
                        $this->brandedTitle($store?->name)
                    );

                    return true;
                } catch (\Throwable $exception) {
                    Log::warning('Failed to send extra flavor prompt.', ['error' => $exception->getMessage()]);
                    // segue pro fluxo normal em vez de travar o cliente
                }
            }
        }

        return $this->beginCustomizationOrCommit($phone, $storeId, $productId, $variationId, $variationName, $variationAdditionalPrice, $quantity);
    }

    /**
     * Botão "Sim"/"Não" de "Deseja adicionar mais algum sabor?".
     */
    public function handleExtraFlavorAnswer(string $phone, bool $wantsExtra): bool
    {
        $state = $this->getState($phone);
        $pending = $state['pending_add'] ?? null;

        if (! is_array($pending) || ($pending['step'] ?? null) !== 'extra_flavor_ask') {
            return false;
        }

        // Só existe combo de fato depois de pelo menos 1 sabor extra escolhido. Se o cliente
        // disser "não" antes de escolher qualquer extra, é só o sabor base — sem preço de
        // combo. Se já tinha escolhido extras e agora diz "não" (ou não sobrou mais sabor
        // pra oferecer), precisa FINALIZAR o combo com o que já foi escolhido.
        $hasExtrasAlready = count($pending['flavors'] ?? []) > 1;

        if (! $wantsExtra) {
            if ($hasExtrasAlready) {
                return $this->finalizeFlavorCombo($phone, $state, $pending);
            }

            return $this->beginCustomizationOrCommit(
                $phone,
                (string) $pending['store_id'],
                (int) $pending['product_id'],
                (int) $pending['variation_id'],
                (string) $pending['variation_name'],
                (float) $pending['variation_additional_price'],
                (int) $pending['quantity']
            );
        }

        $store = Store::query()->where('slug', (string) $pending['store_id'])->first();
        $product = Product::query()->where('id', (int) $pending['product_id'])->first();

        if ($store === null || $product === null) {
            return false;
        }

        $alreadyPickedIds = collect($pending['flavors'] ?? [])->pluck('product_id')->push($product->id)->unique();

        $siblings = Product::query()
            ->where('is_active', true)
            ->where('store_id', $store->id)
            ->where('category_id', $product->category_id)
            ->whereNotIn('id', $alreadyPickedIds)
            ->where('available_for_combo', true)
            ->orderBy('name')
            ->get();

        if ($siblings->isEmpty()) {
            if ($hasExtrasAlready) {
                return $this->finalizeFlavorCombo($phone, $state, $pending);
            }

            return $this->beginCustomizationOrCommit(
                $phone,
                (string) $pending['store_id'],
                (int) $pending['product_id'],
                (int) $pending['variation_id'],
                (string) $pending['variation_name'],
                (float) $pending['variation_additional_price'],
                (int) $pending['quantity']
            );
        }

        $cards = $siblings->take(9)->map(fn (Product $sibling): array => [
            'text' => '🍕 '.$sibling->name."\n\n".trim((string) ($sibling->description ?? '')),
            'image' => $sibling->image_path ?? 'https://picsum.photos/seed/produto-'.(int) $sibling->id.'/600/600',
            'buttons' => [
                ['id' => 'flow_pizza_extra_pick_'.(int) $sibling->id, 'label' => '➕ Adicionar sabor', 'type' => 'REPLY'],
            ],
        ])->values()->all();

        try {
            $this->zapiClient->sendCarousel($phone, '🍕 Escolha o sabor extra:', $cards);

            return true;
        } catch (\Throwable $exception) {
            Log::warning('Failed to send extra flavor carousel.', ['error' => $exception->getMessage()]);

            return false;
        }
    }

    /**
     * Sabor extra escolhido no carrossel. Em lojas pizzaria (motor avançado), acumula até o
     * limite de sabores do tamanho escolhido, perguntando de novo a cada sabor adicionado; em
     * açaiteria/outros, mantém o comportamento histórico (no máximo 1 sabor extra, finaliza
     * na hora). O preço final segue a regra da loja (sabor mais caro — padrão e único modo
     * pra açaiteria — ou média, só disponível pro motor avançado de pizzaria).
     */
    public function handleExtraFlavorPicked(string $phone, string $extraProductId): bool
    {
        $state = $this->getState($phone);
        $pending = $state['pending_add'] ?? null;

        if (! is_array($pending) || ($pending['step'] ?? null) !== 'extra_flavor_ask') {
            return false;
        }

        $extraProduct = Product::query()
            ->with('variations')
            ->where('is_active', true)
            ->where('id', (int) $extraProductId)
            ->first();

        if ($extraProduct === null) {
            return false;
        }

        $store = Store::query()->where('slug', (string) $pending['store_id'])->first();
        $isPizzaAdvanced = $store !== null && $store->isPizzaAdvancedStore();
        $storePizzaSizeId = $pending['store_pizza_size_id'] ?? null;
        $variationName = (string) $pending['variation_name'];

        if ($isPizzaAdvanced && $storePizzaSizeId !== null) {
            $size = StorePizzaSize::find($storePizzaSizeId);
            $extraFlavorPrice = $size !== null
                ? ($this->pizzaPricing->resolveFlavorPrice($extraProduct, $size)['price'] ?? 0.0)
                : 0.0;
        } else {
            // Açaiteria/outros: preço = base do produto + additional_price da variação com o
            // mesmo nome do tamanho já escolhido (comportamento histórico, inalterado).
            $matchingVariation = $extraProduct->variations
                ->first(fn (ProductVariation $v): bool => (bool) $v->is_active
                    && mb_strtolower(trim((string) $v->name)) === mb_strtolower(trim($variationName)));

            if ($matchingVariation === null) {
                Log::warning('Sabor extra sem variação de tamanho correspondente — usando preço base.', [
                    'extra_product_id' => $extraProduct->id,
                    'variation_name'   => $variationName,
                ]);
            }

            $extraFlavorPrice = (float) $extraProduct->price
                + ($matchingVariation !== null ? (float) $matchingVariation->additional_price : 0.0);
        }

        $flavors = $pending['flavors'] ?? [];
        $flavors[] = ['product_id' => $extraProduct->id, 'name' => (string) $extraProduct->name, 'price' => $extraFlavorPrice];
        $maxFlavors = (int) ($pending['max_flavors'] ?? 2);

        $pending['flavors'] = $flavors;

        if (count($flavors) < $maxFlavors) {
            // Ainda cabe mais um sabor nesse tamanho: pergunta de novo em vez de finalizar.
            $state['pending_add'] = $pending;
            $this->saveFlowState($phone, $state);

            try {
                $this->zapiClient->sendButtonActions(
                    $phone,
                    '🍕 Sabor adicionado! Deseja incluir mais um? ('.count($flavors)." de {$maxFlavors})",
                    [
                        ['id' => 'flow_pizza_extra_yes', 'label' => '✅ Sim'],
                        ['id' => 'flow_pizza_extra_no', 'label' => '❌ Não'],
                    ],
                    $this->brandedTitle($store?->name)
                );

                return true;
            } catch (\Throwable $exception) {
                Log::warning('Failed to send extra flavor loop prompt.', ['error' => $exception->getMessage()]);
                // segue pra finalização em vez de travar o cliente
            }
        }

        return $this->finalizeFlavorCombo($phone, $state, $pending);
    }

    /**
     * Fecha a escolha de sabores (limite atingido ou cliente disse "não"): calcula o preço
     * final conforme a regra da loja e segue pro fluxo de borda/customização.
     */
    private function finalizeFlavorCombo(string $phone, array $state, array $pending): bool
    {
        $store = Store::query()->where('slug', (string) $pending['store_id'])->first();
        $rule = $store !== null && $store->isPizzaAdvancedStore() ? $store->pizzaFlavorPriceRule() : 'most_expensive';

        $flavors = $pending['flavors'] ?? [];
        $prices = collect($flavors)->pluck('price')->map(fn ($price) => (float) $price)->all();
        $comboPrice = $this->pizzaPricing->resolveComboPrice($prices, $rule);

        $extraFlavors = collect($flavors)->slice(1)->values();
        $extraProductId = $extraFlavors->isNotEmpty() ? (int) $extraFlavors->last()['product_id'] : null;
        $extraProductName = $extraFlavors->isNotEmpty() ? $extraFlavors->pluck('name')->implode(' + ') : null;

        $state['pending_add'] = array_merge($pending, [
            'step'               => null,
            'extra_product_id'   => $extraProductId,
            'extra_product_name' => $extraProductName,
            'combo_base_price'   => $comboPrice,
        ]);
        $this->saveFlowState($phone, $state);

        return $this->beginCustomizationOrCommit(
            $phone,
            (string) $pending['store_id'],
            (int) $pending['product_id'],
            (int) $pending['variation_id'],
            (string) $pending['variation_name'],
            0.0, // preço já embutido em combo_base_price
            (int) $pending['quantity'],
            $extraProductId,
            $extraProductName,
            $comboPrice
        );
    }

    /**
     * Depois de resolver a variação (ou se o produto não tem variação), decide se precisa
     * andar pelo motor de customização (OptionalFlow/adicionais) antes de comitar a linha do
     * carrinho, ou se pode comitar direto (produto sem nenhum OptionalFlow aplicável).
     */
    private function beginCustomizationOrCommit(
        string $phone,
        string $storeId,
        int $productId,
        ?int $variationId,
        ?string $variationName,
        float $variationAdditionalPrice,
        int $quantity,
        ?int $extraProductId = null,
        ?string $extraProductName = null,
        ?float $overrideBasePrice = null
    ): bool {
        $state = $this->getState($phone);
        $cart = $state['cart'] ?? ['store_id' => null, 'items' => []];

        if (($cart['store_id'] ?? null) !== null
            && $cart['store_id'] !== $storeId
            && is_array($cart['items'] ?? null)
            && $cart['items'] !== []) {
            return $this->sendCartStoreConflictPrompt($phone, (string) $cart['store_id'], $storeId, $productId);
        }

        $product = Product::query()->available()->find($productId);
        $store = Store::query()->find($product?->store_id);

        // Combo com unidades customizáveis (ex.: açaí "Dupla Roxa" = 2 açaís de 500ml) —
        // desvia pro loop de unidades em vez do motor de customização de item único.
        if ($product !== null && (bool) $product->is_combo && $product->comboUnits() !== []) {
            return $this->startComboUnitLoop($phone, $state, $storeId, $product, $quantity);
        }

        $steps = $product !== null ? $this->resolveCustomizationSteps($product) : collect();

        $pendingAdd = [
            'store_id'                   => $storeId,
            'product_id'                 => $productId,
            'variation_id'               => $variationId,
            'variation_name'             => $variationName,
            'variation_additional_price' => $variationAdditionalPrice,
            'quantity'                   => $quantity,
            'extra_product_id'           => $extraProductId,
            'extra_product_name'         => $extraProductName,
            'override_base_price'        => $overrideBasePrice,
        ];

        if ($steps->isEmpty()) {
            return $this->finishCustomizationAndCommit($phone, $state, $pendingAdd, []);
        }

        // Lojas de pizzaria/açaiteria: pergunta explicitamente "quer borda?" antes de mostrar
        // as opções do primeiro step de customização, em vez de já cair direto nele — só
        // quando a linha veio do fluxo de tamanho (variação escolhida). Fora desse caso
        // (produto sem tamanho, ou loja fora do fluxo de sabor), comportamento igual a sempre.
        if ($store !== null && $store->isFlavorMenuStore() && $variationId !== null) {
            $firstStep = $steps->first();
            $remainingSteps = $steps->slice(1)->pluck('id')->values()->all();

            $pendingAdd['flow_step_queue'] = $remainingSteps;
            $pendingAdd['current_step_id'] = null;
            $pendingAdd['selections'] = [];
            $pendingAdd['pending_borda_step_id'] = $firstStep->id;
            $state['pending_add'] = $pendingAdd;
            $this->saveFlowState($phone, $state);

            try {
                $this->zapiClient->sendButtonActions($phone, '🧀 Deseja adicionar uma borda?', [
                    ['id' => 'flow_pizza_borda_yes_'.$firstStep->id, 'label' => '✅ Sim'],
                    ['id' => 'flow_pizza_borda_no_'.$firstStep->id, 'label' => '❌ Não'],
                ], $this->brandedTitle($store?->name));

                return true;
            } catch (\Throwable $exception) {
                Log::warning('Failed to send borda ask prompt.', ['error' => $exception->getMessage()]);
                // segue pro fluxo normal de customização em vez de travar o cliente
            }
        }

        $pendingAdd['flow_step_queue'] = $steps->pluck('id')->values()->all();
        $pendingAdd['current_step_id'] = null;
        $pendingAdd['selections'] = [];
        $state['pending_add'] = $pendingAdd;
        $this->saveFlowState($phone, $state);

        return $this->advanceCustomizationStep($phone);
    }

    /**
     * Botão "Sim"/"Não" de "Deseja adicionar uma borda?" — reinsere o step de borda na fila
     * de customização (se sim) e segue pelo motor de customização normal, sem duplicar nada
     * dele.
     */
    public function confirmWantsBordaStep(string $phone, string $stepId, bool $wantsIt): bool
    {
        $state = $this->getState($phone);
        $pending = $state['pending_add'] ?? null;

        if (! is_array($pending) || (int) ($pending['pending_borda_step_id'] ?? 0) !== (int) $stepId) {
            return false;
        }

        unset($pending['pending_borda_step_id']);

        if ($wantsIt) {
            $queue = $pending['flow_step_queue'] ?? [];
            array_unshift($queue, (int) $stepId);
            $pending['flow_step_queue'] = $queue;
        }

        $state['pending_add'] = $pending;
        $this->saveFlowState($phone, $state);

        return $this->advanceCustomizationStep($phone);
    }

    /**
     * Steps de OptionalFlow aplicáveis ao produto (atribuídos direto a ele ou à categoria
     * dele), na ordem de exibição, só com opções ativas.
     */
    private function resolveCustomizationSteps(Product $product): Collection
    {
        $flowIds = $product->optionalFlows()->where('is_active', true)->pluck('optional_flows.id')
            ->merge(
                OptionalFlow::query()
                    ->where('is_active', true)
                    ->when($product->category_id, fn ($q) => $q->whereHas('categories', fn ($q2) => $q2->whereKey($product->category_id)))
                    ->pluck('id')
            )->unique();

        $legacySteps = $flowIds->isEmpty() ? collect() : OptionalFlowStep::query()
            ->whereIn('optional_flow_id', $flowIds)
            ->with(['options' => fn ($q) => $q->where('is_active', true)->orderBy('position')])
            ->orderBy('position')
            ->get();

        $store = $product->store;

        if ($store === null || ! $store->isPizzaAdvancedStore()) {
            return $legacySteps->filter(fn (OptionalFlowStep $step): bool => $step->options->isNotEmpty())->values();
        }

        return $legacySteps
            ->concat($this->resolvePizzaCustomizationSteps($store, $product))
            ->filter(fn (OptionalFlowStep $step): bool => $step->options->isNotEmpty())
            ->values();
    }

    /**
     * Steps dos 3 fluxos auto-provisionados de pizzaria (bordas/ingredientes/molhos):
     * respeita o toggle de cada recurso (§4) e o vínculo item-a-item do sabor (§13) — só
     * mostra opções explicitamente ligadas a ESSE produto, não tudo que a loja cadastrou.
     */
    private function resolvePizzaCustomizationSteps(Store $store, Product $product): Collection
    {
        $settings = $store->pizzaSettings();
        $featureSettingKeys = ['borda' => 'bordas', 'ingrediente' => 'ingredientes', 'molho' => 'molhos'];

        $enabledFeatureTypes = collect($featureSettingKeys)
            ->filter(fn (string $settingKey) => (bool) ($settings['features'][$settingKey] ?? true))
            ->keys();

        if ($enabledFeatureTypes->isEmpty()) {
            return collect();
        }

        $pizzaFlowIds = OptionalFlow::query()
            ->where('store_id', $store->id)
            ->whereIn('feature_type', $enabledFeatureTypes)
            ->where('is_active', true)
            ->pluck('id');

        if ($pizzaFlowIds->isEmpty()) {
            return collect();
        }

        $linkedOptionIds = $product->optionalFlowStepOptions()->pluck('optional_flow_step_options.id');

        return OptionalFlowStep::query()
            ->whereIn('optional_flow_id', $pizzaFlowIds)
            ->with(['options' => fn ($q) => $q->where('is_active', true)->whereIn('id', $linkedOptionIds)->orderBy('position')])
            ->orderBy('position')
            ->get();
    }

    /**
     * Anda pra o próximo step da fila de customização, ou comita a linha do carrinho se a
     * fila já acabou.
     */
    public function advanceCustomizationStep(string $phone): bool
    {
        $state = $this->getState($phone);
        $pending = $state['pending_add'] ?? null;

        if (! is_array($pending)) {
            return false;
        }

        $queue = $pending['flow_step_queue'] ?? [];

        if ($queue === []) {
            return $this->commitPendingCustomization($phone, $state, $pending);
        }

        $stepId = (int) array_shift($queue);
        $pending['flow_step_queue'] = $queue;
        $pending['current_step_id'] = $stepId;
        $state['pending_add'] = $pending;
        $this->saveFlowState($phone, $state);

        $step = OptionalFlowStep::query()
            ->with(['options' => fn ($q) => $q->where('is_active', true)->orderBy('position')])
            ->find($stepId);

        if ($step === null || $step->options->isEmpty()) {
            // Step sem opções ativas (mudou entre a query inicial e agora): pula.
            return $this->advanceCustomizationStep($phone);
        }

        $unitPrefix = $this->comboUnitPrefix($pending);
        $storeName = $this->storeNameBySlug($pending['store_id'] ?? null);

        if ((int) $step->max_select <= 1) {
            return $this->sendCustomizationOptionPrompt($phone, $step, $unitPrefix, $storeName);
        }

        if (! (bool) $step->is_required) {
            return $this->sendCustomizationAskPrompt($phone, $step, $unitPrefix, $storeName);
        }

        return $this->sendCustomizationMultiSelectPrompt($phone, $step, $unitPrefix, $storeName);
    }

    /**
     * "Açaí 1 de 2" — prefixo mostrado nos prompts de customização quando o item vem de um
     * combo com múltiplas unidades (ver startComboUnitLoop/advanceComboUnit). `null` fora do
     * fluxo de combo, ou quando o combo só tem 1 unidade (não faz sentido numerar).
     */
    private function comboUnitPrefix(array $pending): ?string
    {
        $total = (int) ($pending['combo_total_units'] ?? 0);

        if ($total < 2) {
            return null;
        }

        $index = (int) ($pending['combo_unit_index'] ?? 1);
        $label = (string) ($pending['combo_unit_label'] ?? 'Item');

        return "{$label} {$index} de {$total}";
    }

    /**
     * Step obrigatório de seleção única (ex.: tamanho): botões WhatsApp — limite de 3 por
     * mensagem, opções além da 3ª (por position) ficam de fora.
     */
    private function sendCustomizationOptionPrompt(string $phone, OptionalFlowStep $step, ?string $unitPrefix = null, ?string $storeName = null): bool
    {
        $question = trim((string) ($step->customer_hint ?: $step->title));
        if ($unitPrefix !== null) {
            $question = $unitPrefix.' — '.$question;
        }

        $buttons = $step->options->take(3)->map(function (OptionalFlowStepOption $option) use ($step): array {
            $price = $this->optionEffectivePrice($option);

            return [
                'id'    => 'flow_custopt_'.$step->id.'_'.$option->id,
                'label' => $option->title.($price > 0 ? ' (+R$ '.number_format($price, 2, ',', '.').')' : ''),
            ];
        })->values()->all();

        try {
            $this->zapiClient->sendButtonActions($phone, $question, $buttons, $this->brandedTitle($storeName));

            return true;
        } catch (\Throwable $exception) {
            Log::warning('Failed to send customization option buttons.', ['error' => $exception->getMessage()]);

            return false;
        }
    }

    /**
     * Preço real de uma opção de adicional: painel do lojista grava em `merchant_price`
     * (fallback `base_price`), coluna `price` só é usada quando nenhum dos dois foi
     * configurado. Mesma cadeia usada por `OptionalFlowController::show` (campo
     * `effective_price`) e `PizzaPricingResolver` — ler `price` direto ignorava o valor real
     * cadastrado pelo lojista e fazia o adicional entrar de graça no carrinho.
     */
    private function optionEffectivePrice(OptionalFlowStepOption $option): float
    {
        return (float) ($option->merchant_price ?? $option->base_price ?? $option->price ?? 0);
    }

    /**
     * Step opcional de múltipla seleção (ex.: adicionais): pergunta primeiro se o cliente
     * quer ver as opções (documento §6, Passo 1).
     */
    private function sendCustomizationAskPrompt(string $phone, OptionalFlowStep $step, ?string $unitPrefix = null, ?string $storeName = null): bool
    {
        $prefix = $unitPrefix !== null ? $unitPrefix."\n" : '';
        $message = $prefix.trim((string) ($step->customer_hint ?: $step->title))."\nQuer colocar algum adicional?";

        try {
            $this->zapiClient->sendButtonActions($phone, $message, [
                ['id' => 'flow_custask_'.$step->id, 'label' => '➕ Sim, quero adicionais'],
                ['id' => 'flow_custskip_'.$step->id, 'label' => '🚫 Não, obrigado'],
            ], $this->brandedTitle($storeName));

            return true;
        } catch (\Throwable $exception) {
            Log::warning('Failed to send customization ask prompt.', ['error' => $exception->getMessage()]);

            return false;
        }
    }

    /**
     * Lista interativa com seleção múltipla (documento §6, Passo 2): cada toque só registra
     * a seleção, sem responder — a confirmação consolidada sai depois do debounce.
     */
    private function sendCustomizationMultiSelectPrompt(string $phone, OptionalFlowStep $step, ?string $unitPrefix = null, ?string $storeName = null): bool
    {
        $icon = $step->charge_type === 'free' ? '🍌' : '➕';

        $hint = trim((string) ($step->customer_hint ?: '➕ Escolha os adicionais que quiser (pode marcar mais de um):'));

        // Contador só faz sentido pro step obrigatório de limite (ex.: complementos grátis do
        // açaí, §8.1) — não polui adicionais de hambúrguer nem o step opcional pago.
        if ((bool) $step->is_required && (int) $step->max_select > 1) {
            $hint .= ' (0/'.$step->max_select.' selecionados)';
        }

        if ($unitPrefix !== null) {
            $hint = $unitPrefix.' — '.$hint;
        }

        // Botões WhatsApp: limite de 3 por mensagem. Até 8 adicionais no total, mandados em
        // mensagens sequenciais de 3 (3+3+2) — todos os botões usam o mesmo id
        // flow_custopt_{step}_{option}, então o acúmulo/debounce funciona igual não importa
        // em qual mensagem o cliente tocou.
        $chunks = $step->options->take(8)->values()->chunk(3);
        $ok = true;

        foreach ($chunks as $i => $chunk) {
            $buttons = $chunk->map(function (OptionalFlowStepOption $option) use ($step, $icon): array {
                $price = $this->optionEffectivePrice($option);

                return [
                    'id'    => 'flow_custopt_'.$step->id.'_'.$option->id,
                    'label' => $icon.' '.$option->title.($price > 0 ? ' +R$ '.number_format($price, 2, ',', '.') : ''),
                ];
            })->values()->all();

            $text = $i === 0 ? $hint : $icon.' Mais opções:';

            try {
                $this->zapiClient->sendButtonActions($phone, $text, $buttons, $this->brandedTitle($storeName));
            } catch (\Throwable $exception) {
                Log::warning('Failed to send customization multi-select buttons.', ['error' => $exception->getMessage()]);
                $ok = false;
            }
        }

        return $ok;
    }

    /**
     * Botão "Sim, quero adicionais" / "Não, obrigado" do passo opcional de múltipla seleção.
     */
    public function confirmWantsCustomizationStep(string $phone, string $stepId, bool $wantsIt): bool
    {
        $state = $this->getState($phone);
        $pending = $state['pending_add'] ?? null;

        if (! is_array($pending) || (int) ($pending['current_step_id'] ?? 0) !== (int) $stepId) {
            return false;
        }

        if (! $wantsIt) {
            // Cliente recusou explicitamente ("Não, obrigado"): marca o step como fechado
            // pra esse item — toque tardio nos botões de adicionais que ainda ficam
            // clicáveis no chat (appendLateCustomizationTap) não deve mais anexar nada aqui,
            // já que o cliente já disse que não queria.
            $pending['declined_steps'] = array_values(array_unique(array_merge(
                $pending['declined_steps'] ?? [],
                [(int) $stepId]
            )));
            $state['pending_add'] = $pending;
            $this->saveFlowState($phone, $state);

            return $this->advanceCustomizationStep($phone);
        }

        $step = OptionalFlowStep::query()
            ->with(['options' => fn ($q) => $q->where('is_active', true)->orderBy('position')])
            ->find((int) $stepId);

        if ($step === null || $step->options->isEmpty()) {
            return $this->advanceCustomizationStep($phone);
        }

        return $this->sendCustomizationMultiSelectPrompt($phone, $step, $this->comboUnitPrefix($pending));
    }

    /**
     * Toque numa opção de customização — tanto pra seleção única (avança na hora) quanto pra
     * múltipla seleção (só registra e reinicia o debounce de 2-3s).
     */
    public function handleCustomizationOptionSelected(string $phone, string $stepId, string $optionId): bool
    {
        $state = $this->getState($phone);
        $pending = $state['pending_add'] ?? null;

        if (! is_array($pending) || (int) ($pending['current_step_id'] ?? 0) !== (int) $stepId) {
            // Toque tardio: a lista continua clicável no chat mesmo depois do debounce ter
            // commitado o item (ex.: cliente escolheu Bacon, a confirmação saiu, e ele volta
            // na lista e toca Cheddar). Anexa ao item já commitado, respeitando max_select.
            return $this->appendLateCustomizationTap($phone, (int) $stepId, (int) $optionId);
        }

        $step = OptionalFlowStep::find((int) $stepId);

        if ($step === null) {
            return false;
        }

        $selections = $pending['selections'] ?? [];
        $stepSelections = $selections[(int) $stepId] ?? [];
        $maxSelect = (int) $step->max_select;

        // Toque extra depois de atingir o limite (ex.: 3 complementos grátis já escolhidos):
        // a lista do WhatsApp não pode ser desabilitada no meio da conversa, então ignoramos
        // silenciosamente em vez de deixar o cliente estourar o limite configurado.
        if ($maxSelect > 1 && count($stepSelections) >= $maxSelect && ! in_array((int) $optionId, $stepSelections, true)) {
            return true;
        }

        if (! in_array((int) $optionId, $stepSelections, true)) {
            $stepSelections[] = (int) $optionId;
        }

        $selections[(int) $stepId] = $stepSelections;
        $pending['selections'] = $selections;
        $state['pending_add'] = $pending;
        $this->saveFlowState($phone, $state);

        if ($maxSelect <= 1) {
            return $this->advanceCustomizationStep($phone);
        }

        // Atingiu o limite agora: pula o debounce e avança na hora — o "aviso" do limite é o
        // próprio próximo prompt (ex.: pergunta de complemento pago).
        if (count($stepSelections) >= $maxSelect) {
            return $this->advanceCustomizationStep($phone);
        }

        $nonce = now()->getTimestampMs();
        Cache::put('zapi:customization:nonce:'.$phone, $nonce, 60);

        AdvanceCustomizationStepJob::dispatch($phone, $nonce)
            ->delay(now()->addSeconds(3));

        return true;
    }

    /**
     * Fim da fila de customização: resolve as seleções em customizations e comita a linha do
     * carrinho.
     */
    private function commitPendingCustomization(string $phone, array $state, array $pending): bool
    {
        $customizations = $this->resolveCustomizationsFromSelections($pending['selections'] ?? []);

        // Dentro do loop de combo (ex.: açaí "Dupla Roxa"): fecha a unidade atual e avança
        // pra próxima, em vez de comitar a linha do carrinho agora — só a finalização do
        // combo inteiro (finalizeComboAndCommit) faz isso.
        if (array_key_exists('combo_units_queue', $pending)) {
            return $this->commitComboUnitAndAdvance($phone, $state, $pending, $customizations);
        }

        return $this->finishCustomizationAndCommit($phone, $state, $pending, $customizations);
    }

    /**
     * Toque numa lista de customização depois do item já ter sido commitado no carrinho:
     * anexa a opção à linha correspondente (a mais recente cujo produto usa esse step),
     * respeitando max_select e sem duplicar, e reemite a confirmação consolidada via
     * debounce. Toque além do limite é ignorado em silêncio (a lista do WhatsApp não pode
     * ser desativada remotamente).
     */
    private function appendLateCustomizationTap(string $phone, int $stepId, int $optionId): bool
    {
        $step = OptionalFlowStep::find($stepId);

        if ($step === null) {
            return false;
        }

        $lock = Cache::lock('zapi:cart:lock:'.$phone, 10);

        try {
            $lock->block(5);

            $state = $this->getState($phone);
            $items = $state['cart']['items'] ?? [];

            if (! is_array($items) || $items === []) {
                return false;
            }

            // Procura, do item mais recente pro mais antigo, a linha cujo produto tem esse
            // step aplicável. Combos ficam de fora — o toque tardio não sabe a qual unidade
            // pertenceria.
            $targetIndex = null;
            foreach (array_reverse(array_keys($items)) as $index) {
                $item = $items[$index];

                if (! empty($item['is_combo'])) {
                    continue;
                }

                // Cliente já recusou esse step explicitamente ("Não, obrigado") pra esse
                // item — botão antigo continua clicável no chat, mas não deve mais anexar
                // nada (ver confirmWantsCustomizationStep).
                if (in_array($stepId, array_map('intval', $item['declined_steps'] ?? []), true)) {
                    continue;
                }

                $product = Product::find((int) ($item['product_id'] ?? 0));
                if ($product === null) {
                    continue;
                }

                $applicableStepIds = $this->resolveCustomizationSteps($product)->pluck('id')->all();
                if (in_array($stepId, array_map('intval', $applicableStepIds), true)) {
                    $targetIndex = $index;
                    break;
                }
            }

            if ($targetIndex === null) {
                return false;
            }

            $customizations = is_array($items[$targetIndex]['customizations'] ?? null)
                ? $items[$targetIndex]['customizations']
                : [];

            // Duplicado ou já no limite do step: ignora sem responder (mesma regra do fluxo
            // pré-commit).
            $stepCustomizations = array_filter($customizations, fn (array $c): bool => (int) ($c['step_id'] ?? 0) === $stepId);

            foreach ($stepCustomizations as $existing) {
                if ((int) ($existing['option_id'] ?? 0) === $optionId) {
                    return true;
                }
            }

            if ((int) $step->max_select > 0 && count($stepCustomizations) >= (int) $step->max_select) {
                return true;
            }

            $option = OptionalFlowStepOption::query()->where('is_active', true)->find($optionId);
            if ($option === null || (int) $option->optional_flow_step_id !== $stepId) {
                return false;
            }

            $customizations[] = [
                'step_id' => $stepId,
                'option_id' => $optionId,
                'label' => (string) $option->title,
                'price' => $this->optionEffectivePrice($option),
                'charge_type' => (string) ($step->charge_type ?? 'paid'),
            ];

            $items[$targetIndex]['customizations'] = $customizations;
            $items[$targetIndex]['customizations_total'] = array_sum(array_map(
                static fn (array $c): float => (float) ($c['price'] ?? 0),
                $customizations
            ));

            $state['cart']['items'] = $items;
            // Reemite a confirmação consolidada do item atualizado ("✅ X adicionado!
            // ➕ Adicionais: Bacon, Cheddar") depois do debounce — mais toques tardios dentro
            // da janela continuam agrupando.
            $state['feedback_pending'] = [$targetIndex];
            $this->saveFlowState($phone, $state);

            $nonce = now()->getTimestampMs();
            Cache::put('zapi:feedback:nonce:'.$phone, $nonce, 60);
            SendCartFeedbackJob::dispatch($phone, $nonce)->delay(now()->addSeconds(3));

            return true;
        } catch (\Throwable $e) {
            Log::warning('Falha ao anexar toque tardio de customização: '.$e->getMessage());

            return false;
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * @param array<int, array<int, int>> $selections step_id => [option_id, ...]
     * @return array<int, array{step_id: int, option_id: int, label: string, price: float, charge_type: string}>
     */
    private function resolveCustomizationsFromSelections(array $selections): array
    {
        $optionIds = collect($selections)->flatten()->map(fn ($id): int => (int) $id)->unique()->values();

        if ($optionIds->isEmpty()) {
            return [];
        }

        $options = OptionalFlowStepOption::query()->whereIn('id', $optionIds)->get()->keyBy('id');
        $stepChargeTypes = OptionalFlowStep::query()
            ->whereIn('id', array_map('intval', array_keys($selections)))
            ->pluck('charge_type', 'id');
        $customizations = [];

        foreach ($selections as $stepId => $ids) {
            foreach ((array) $ids as $optionId) {
                $option = $options->get((int) $optionId);

                if ($option === null) {
                    continue;
                }

                $customizations[] = [
                    'step_id'   => (int) $stepId,
                    'option_id' => (int) $optionId,
                    'label'     => (string) $option->title,
                    'price'     => $this->optionEffectivePrice($option),
                    // 'free' = complemento grátis (§8.1), 'paid'/outros = adicional pago —
                    // usado na confirmação pra separar "🍌 Complementos" de "➕ Adicionais".
                    'charge_type' => (string) ($stepChargeTypes->get((int) $stepId) ?? 'paid'),
                ];
            }
        }

        return $customizations;
    }

    /**
     * Ponto único de saída do motor de customização (chamado tanto quando o produto não tem
     * nenhum step aplicável quanto quando a fila de steps termina): em lojas pizzaria com
     * "Observações do cliente" ativado, pergunta a observação antes de comitar; caso
     * contrário, comita direto — comportamento padrão, inalterado.
     */
    private function finishCustomizationAndCommit(string $phone, array $state, array $pendingAdd, array $customizations): bool
    {
        $store = Store::query()->where('slug', (string) ($pendingAdd['store_id'] ?? ''))->first();

        if ($store !== null && $store->isPizzaAdvancedStore() && $store->pizzaFeatureEnabled('observacoes')
            && empty($pendingAdd['awaiting_observation'])) {
            $pendingAdd['selections'] = [];
            $pendingAdd['resolved_customizations'] = $customizations;
            $pendingAdd['awaiting_observation'] = true;
            $state['pending_add'] = $pendingAdd;
            $this->saveFlowState($phone, $state);

            try {
                $this->zapiClient->sendText($phone, '📝 Deseja deixar alguma observação para este item? Responda com o texto, ou envie *pular*.');

                return true;
            } catch (\Throwable $exception) {
                Log::warning('Failed to send observation prompt.', ['error' => $exception->getMessage()]);
                // segue pra comitar direto em vez de travar o cliente
            }
        }

        unset($state['pending_add']);
        $this->saveFlowState($phone, $state);

        return $this->commitAndSendFeedback(
            $phone,
            (string) ($pendingAdd['store_id'] ?? ''),
            (int) ($pendingAdd['product_id'] ?? 0),
            $pendingAdd['variation_id'] ?? null,
            $pendingAdd['variation_name'] ?? null,
            (float) ($pendingAdd['variation_additional_price'] ?? 0.0),
            (int) ($pendingAdd['quantity'] ?? 1),
            $customizations,
            isset($pendingAdd['extra_product_id']) ? (int) $pendingAdd['extra_product_id'] : null,
            $pendingAdd['extra_product_name'] ?? null,
            isset($pendingAdd['override_base_price']) ? (float) $pendingAdd['override_base_price'] : null,
            null,
            $pendingAdd['declined_steps'] ?? []
        );
    }

    /**
     * Combo com N unidades customizáveis (ex.: açaí "Dupla Roxa" = 2 açaís de 500ml, cada um
     * com seus próprios complementos) — §8.2. Cada unidade replay o motor de customização
     * normal (resolveCustomizationSteps/advanceCustomizationStep), sem duplicar nada dele;
     * só a finalização (commitPendingCustomization → combo_units_queue) desvia pra cá.
     */
    private function startComboUnitLoop(string $phone, array $state, string $storeId, Product $product, int $quantity): bool
    {
        $pendingAdd = [
            'store_id'           => $storeId,
            'product_id'         => $product->id,
            'quantity'           => $quantity,
            'combo_units_queue'  => $product->comboUnits(),
            'combo_total_units'  => count($product->comboUnits()),
            'combo_unit_index'   => 0,
            'combo_completed'    => [],
        ];

        $state['pending_add'] = $pendingAdd;
        $this->saveFlowState($phone, $state);

        return $this->advanceComboUnit($phone);
    }

    /**
     * Consome a próxima unidade da fila do combo e roda o motor de customização normal pra
     * ela; ao esvaziar a fila, finaliza o combo inteiro numa única linha do carrinho.
     */
    private function advanceComboUnit(string $phone): bool
    {
        $state = $this->getState($phone);
        $pending = $state['pending_add'] ?? null;

        if (! is_array($pending) || ! array_key_exists('combo_units_queue', $pending)) {
            return false;
        }

        $queue = $pending['combo_units_queue'];

        if ($queue === []) {
            return $this->finalizeComboAndCommit($phone, $state, $pending);
        }

        $unit = array_shift($queue);
        $pending['combo_units_queue'] = $queue;
        $pending['combo_unit_index'] = (int) ($pending['combo_unit_index'] ?? 0) + 1;

        $unitProduct = Product::query()->available()->find((int) ($unit['product_id'] ?? 0));

        if ($unitProduct === null) {
            // Unidade configurada ficou indisponível (esgotou/desativou) entre o cadastro do
            // combo e agora: pula sem travar o cliente, registrando a unidade vazia.
            $completed = $pending['combo_completed'] ?? [];
            $completed[] = ['label' => (string) ($unit['label'] ?? 'Item'), 'customizations' => []];
            $pending['combo_completed'] = $completed;
            $state['pending_add'] = $pending;
            $this->saveFlowState($phone, $state);

            return $this->advanceComboUnit($phone);
        }

        $pending['combo_unit_label'] = (string) ($unit['label'] ?? $unitProduct->name);

        $steps = $this->resolveCustomizationSteps($unitProduct);
        $pending['flow_step_queue'] = $steps->pluck('id')->values()->all();
        $pending['current_step_id'] = null;
        $pending['selections'] = [];
        $state['pending_add'] = $pending;
        $this->saveFlowState($phone, $state);

        return $this->advanceCustomizationStep($phone);
    }

    /**
     * Fecha a unidade atual do combo (guarda as customizations dela) e avança pra próxima —
     * ou finaliza, se essa era a última.
     */
    private function commitComboUnitAndAdvance(string $phone, array $state, array $pending, array $customizations): bool
    {
        $completed = $pending['combo_completed'] ?? [];
        $completed[] = [
            'label'          => (string) ($pending['combo_unit_label'] ?? ('Item '.($pending['combo_unit_index'] ?? ''))),
            'customizations' => $customizations,
        ];

        $pending['combo_completed'] = $completed;
        unset($pending['selections'], $pending['current_step_id'], $pending['flow_step_queue'], $pending['combo_unit_label']);
        $state['pending_add'] = $pending;
        $this->saveFlowState($phone, $state);

        return $this->advanceComboUnit($phone);
    }

    private function finalizeComboAndCommit(string $phone, array $state, array $pending): bool
    {
        unset($state['pending_add']);
        $this->saveFlowState($phone, $state);

        return $this->commitComboCartItem(
            $phone,
            (string) ($pending['store_id'] ?? ''),
            (int) ($pending['product_id'] ?? 0),
            (int) ($pending['quantity'] ?? 1),
            $pending['combo_completed'] ?? []
        );
    }

    /**
     * Comita a linha de carrinho do combo inteiro (mesmo lock/debounce de
     * commitAndSendFeedback) — uma única linha com `is_combo: true` e o array `combo_units`
     * por unidade, em vez de uma linha por unidade.
     */
    private function commitComboCartItem(string $phone, string $storeId, int $productId, int $quantity, array $comboUnits): bool
    {
        $lock = Cache::lock('zapi:cart:lock:'.$phone, 10);

        try {
            $lock->block(5);

            $store = Store::query()->where('is_active', true)->where('slug', $storeId)->first();
            if (! $store) {
                return false;
            }

            $product = Product::query()->available()->where('store_id', $store->id)->where('id', $productId)->first();
            if (! $product) {
                return false;
            }

            $state = $this->flow->getState($phone);
            $cart = $state['cart'] ?? ['store_id' => null, 'items' => []];

            if (($cart['store_id'] ?? null) !== null && $cart['store_id'] !== $storeId && ! empty($cart['items'])) {
                $lock->release();

                return $this->sendCartStoreConflictPrompt($phone, (string) $cart['store_id'], $storeId, $productId);
            }

            $customizationsTotal = array_sum(array_map(
                static fn (array $unit): float => array_sum(array_map(
                    static fn (array $c): float => (float) ($c['price'] ?? 0),
                    $unit['customizations'] ?? []
                )),
                $comboUnits
            ));

            $cart['store_id'] = $storeId;
            $cart['items'][] = [
                'product_id'           => $productId,
                'product_name'         => (string) $product->name,
                'base_price'           => (float) $product->price,
                'variation_id'         => null,
                'variation_name'       => null,
                'additional_price'     => 0.0,
                'customizations'       => [],
                'customizations_total' => $customizationsTotal,
                'quantity'             => $quantity,
                'observation'          => null,
                'extra_product_id'     => null,
                'extra_product_name'   => null,
                'is_combo'             => true,
                'combo_units'          => $comboUnits,
                'from_search'          => (bool) Cache::pull('zapi:ai_search_flag:'.$phone.':'.$storeId.':'.$productId, false),
            ];

            $state['cart'] = $cart;
            $state['selected_store_id'] = $storeId;
            $state['feedback_pending'] = array_merge($state['feedback_pending'] ?? [], [count($cart['items']) - 1]);
            unset($state['pending_add']);
            $this->saveFlowState($phone, $state);

            $nonce = now()->getTimestampMs();
            Cache::put('zapi:feedback:nonce:'.$phone, $nonce, 60);
            SendCartFeedbackJob::dispatch($phone, $nonce)->delay(now()->addSeconds(3));

            return true;
        } catch (\Throwable $e) {
            Log::warning('Erro ao salvar combo no carrinho: '.$e->getMessage());

            return false;
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * Texto livre recebido enquanto o carrinho aguarda a observação do cliente (só acontece
     * em lojas pizzaria com o recurso "Observações do cliente" ativado — ver
     * commitPendingCustomization). Chamado pelo TextHandler antes do checkout_step.
     */
    public function handleObservationTextInput(string $phone, string $text): bool
    {
        $state = $this->getState($phone);
        $pending = $state['pending_add'] ?? null;

        if (! is_array($pending) || empty($pending['awaiting_observation'])) {
            return false;
        }

        $trimmed = trim($text);
        $isSkip = in_array(mb_strtolower($trimmed), ['pular', 'não', 'nao', 'skip', 'n'], true);
        $observation = (! $isSkip && $trimmed !== '') ? $trimmed : null;

        unset($state['pending_add']);
        $this->saveFlowState($phone, $state);

        return $this->commitAndSendFeedback(
            $phone,
            (string) ($pending['store_id'] ?? ''),
            (int) ($pending['product_id'] ?? 0),
            $pending['variation_id'] ?? null,
            $pending['variation_name'] ?? null,
            (float) ($pending['variation_additional_price'] ?? 0.0),
            (int) ($pending['quantity'] ?? 1),
            $pending['resolved_customizations'] ?? [],
            isset($pending['extra_product_id']) ? (int) $pending['extra_product_id'] : null,
            $pending['extra_product_name'] ?? null,
            isset($pending['override_base_price']) ? (float) $pending['override_base_price'] : null,
            $observation
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

        // §11.1 — remoção + carrinho atualizado (ou aviso de vazio) numa mensagem só.
        return $this->sendCartSummary($phone, "🗑️ {$itemName} removido!");
    }

    /**
     * Botão "🍔 Ver Cardápio" do carrinho vazio (§11.1): volta pro carrossel de
     * produtos/categorias DA MESMA loja em que o cliente estava.
     */
    public function handleAddMoreItems(string $phone): bool
    {
        $state = $this->flow->getState($phone);
        $storeSlug = $state['cart']['store_id'] ?? $state['selected_store_id'] ?? null;

        if ($storeSlug) {
            return $this->storeHandle->selectStore($phone, (string) $storeSlug);
        }

        // Fallback: Se por algum motivo o estado sumiu, manda escolher a loja
        return (bool) $this->storeHandle->sendStoresPage($phone, 0);
    }

    /**
     * §11.1 — carrossel de edição: um card por item, com os DOIS botões do documento
     * (Adicionar observação + Remover). Sem card extra de "adicionar mais".
     */
    public function sendEditCartCarousel(string $phone): bool
    {
        $state = $this->flow->getState($phone);
        $items = $this->normalizeCartItems($state['cart']['items'] ?? []);

        if (empty($items)) {
            return $this->sendEmptyCartMessage($phone);
        }

        $cards = [];

        foreach ($items as $index => $item) {
            $label = $this->cartItemLabel($item);

            $product = Product::find($item['product_id']);
            $imageUrl = ($product && !empty($product->image_path))
                        ? $product->image_path
                        : 'https://picsum.photos/seed/padrao-'.$item['product_id'].'/600/600';

            $cards[] = [
                'text' => "{$label} ({$item['quantity']}x)",
                'image' => $imageUrl,
                'buttons' => [
                    ['id' => "obs_item_{$index}", 'label' => '📝 Adicionar observação', 'type' => 'REPLY'],
                    ['id' => "cart_remove_{$index}", 'label' => '🗑️ Remover', 'type' => 'REPLY'],
                ]
            ];

            // Limite do WhatsApp (máx 10 cards)
            if (count($cards) >= 10) {
                break;
            }
        }

        try {
            $this->zapiClient->sendCarousel($phone, "✏️ *Modo de Edição:*", $cards);
            return true;
        } catch (\Throwable $e) {
            Log::error('Erro ao enviar carrossel de edição: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * §11.2 caminho B — botão "📝 Adicionar observação" da confirmação pós-adição: o bot
     * ainda não sabe qual item, então abre a lista de itens do carrinho. Com 1 item só, pula
     * a lista e vai direto pro texto (mesmo destino do caminho A).
     */
    public function startObservationPicker(string $phone): bool
    {
        $state = $this->flow->getState($phone);
        $items = $this->normalizeCartItems($state['cart']['items'] ?? []);

        if ($items === []) {
            return $this->sendEmptyCartMessage($phone);
        }

        if (count($items) === 1) {
            return $this->promptObservationForItem($phone, 0);
        }

        // Botões WhatsApp: limite de 3 por mensagem — itens além do 3º ficam de fora.
        $buttons = [];
        foreach (array_slice($items, 0, 3, true) as $index => $item) {
            $buttons[] = [
                'id'    => 'obs_item_'.$index,
                'label' => mb_substr($this->cartItemLabel($item).' ('.$item['quantity'].'x)', 0, 24),
            ];
        }

        try {
            $title = $this->brandedTitle($this->storeNameBySlug($state['cart']['store_id'] ?? null));
            $this->zapiClient->sendButtonActions($phone, '📝 Para qual item você quer deixar uma observação?', $buttons, $title);

            return true;
        } catch (\Throwable $exception) {
            Log::warning('Failed to send observation item buttons.', ['error' => $exception->getMessage()]);

            return false;
        }
    }

    /**
     * §11.2 caminho A — o item já é conhecido (clique no card do Editar Carrinho, ou lista do
     * caminho B): pede o texto da observação.
     */
    public function promptObservationForItem(string $phone, int $index): bool
    {
        $state = $this->flow->getState($phone);
        $items = $this->normalizeCartItems($state['cart']['items'] ?? []);

        if (! isset($items[$index])) {
            return $this->sendCartSummary($phone);
        }

        $state['awaiting_cart_observation'] = $index;
        $this->saveFlowState($phone, $state);

        $label = $this->cartItemLabel($items[$index]);

        try {
            $this->zapiClient->sendText(
                $phone,
                "📝 Pode digitar a observação para o {$label} ({$items[$index]['quantity']}x):\n"
                .'(ex: sem cebola, ponto da carne, sem maionese...)'
            );

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Texto digitado enquanto `awaiting_cart_observation` está setado (chamado pelo
     * TextHandler): salva vinculado ao item e confirma com os botões do §11.2.
     */
    public function handleCartObservationText(string $phone, string $text): bool
    {
        $state = $this->flow->getState($phone);
        $index = $state['awaiting_cart_observation'] ?? null;

        if ($index === null) {
            return false;
        }

        unset($state['awaiting_cart_observation']);

        $trimmed = trim($text);
        if (isset($state['cart']['items'][(int) $index]) && $trimmed !== '') {
            $state['cart']['items'][(int) $index]['observation'] = $trimmed;
        }

        $this->saveFlowState($phone, $state);

        try {
            $this->zapiClient->sendButtonActions(
                $phone,
                "📝 Observação anotada!\nQuer adicionar mais alguma observação?",
                [
                    ['id' => 'obs_pick', 'label' => '📝 Adicionar outra'],
                    ['id' => 'flow_cart', 'label' => '🛒 Voltar ao carrinho'],
                ],
                $this->brandedTitle($this->storeNameBySlug($state['cart']['store_id'] ?? null))
            );

            return true;
        } catch (\Throwable) {
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
        int $quantity,
        array $customizations = [],
        ?int $extraProductId = null,
        ?string $extraProductName = null,
        ?float $overrideBasePrice = null,
        ?string $observation = null,
        array $declinedSteps = []
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
                ->available()
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

            $customizationsTotal = array_sum(array_map(
                static fn (array $customization): float => (float) ($customization['price'] ?? 0),
                $customizations
            ));

            // §1.1.b — se esse produto foi mostrado no carrossel de busca por IA (flag
            // transitório gravado em ProductsHandler::sendProductSearchCarousel), a
            // confirmação pós-adição ganha o 3º botão "Cardápio da loja" (ver
            // sendCartFeedbackNow). Cache::pull já remove o flag, então só a primeira adição
            // desse produto conta como vinda da busca.
            $fromSearch = (bool) Cache::pull('zapi:ai_search_flag:'.$phone.':'.$storeId.':'.$productId, false);

            // Adiciona o item ao array do carrinho. Item "meio a meio" (overrideBasePrice
            // vindo do combo de sabores) já traz o preço do sabor mais caro embutido em
            // base_price — additional_price fica zerado pra não somar duas vezes.
            $cart['store_id'] = $storeId;
            $cart['items'][] = [
                'product_id'            => $productId,
                'product_name'          => (string) $product->name,
                'base_price'            => $overrideBasePrice ?? (float) $product->price,
                'variation_id'          => $variationId,
                'variation_name'        => $variationName,
                'additional_price'      => $overrideBasePrice !== null ? 0.0 : $variationAdditionalPrice,
                'customizations'        => $customizations,
                'customizations_total'  => $customizationsTotal,
                'quantity'              => $quantity,
                'observation'           => $observation,
                'extra_product_id'      => $extraProductId,
                'extra_product_name'    => $extraProductName,
                'declined_steps'        => array_values(array_unique(array_map('intval', $declinedSteps))),
                'from_search'           => $fromSearch,
            ];

            $state['cart'] = $cart;
            $state['selected_store_id'] = $storeId;
            // Fila do debounce (§9): guarda o índice da linha recém-adicionada pra
            // confirmação consolidada listar só o que entrou agora, não o carrinho inteiro.
            $state['feedback_pending'] = array_merge($state['feedback_pending'] ?? [], [count($cart['items']) - 1]);
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
     * Confirmação consolidada pós-debounce (§6 Passo 3 / §9): lista SÓ o que foi adicionado
     * desde a última confirmação — sem subtotal/valores (reservados pro carrinho e pro resumo
     * final) — com os botões do documento.
     */
    public function sendCartFeedbackNow(string $phone): void
    {
        $state = $this->flow->getState($phone);
        $cart  = $state['cart'] ?? ['store_id' => null, 'items' => []];
        $cartItems = $this->normalizeCartItems($cart['items'] ?? []);

        if ($cartItems === []) {
            return;
        }

        $pendingIndexes = array_values(array_unique(array_map('intval', $state['feedback_pending'] ?? [])));
        $addedItems = $pendingIndexes === []
            ? $cartItems
            : array_values(array_filter(array_map(
                fn (int $index): ?array => $cartItems[$index] ?? null,
                $pendingIndexes
            )));

        if ($addedItems === []) {
            $addedItems = $cartItems;
        }

        unset($state['feedback_pending']);
        $this->saveFlowState($phone, $state);

        if (count($addedItems) === 1) {
            $message = $this->singleItemFeedbackText($addedItems[0]);
        } else {
            $lines = ['✅ *Itens adicionados:*'];
            foreach ($addedItems as $item) {
                $lines[] = '• '.$this->cartItemLabel($item);
            }
            $message = implode("\n", $lines);
        }

        $buttons = [
            ['id' => 'flow_cart', 'label' => '🛒 Ver carrinho'],
            ['id' => 'obs_pick', 'label' => '📝 Adicionar observação'],
        ];

        // §1.1.b — variação exclusiva do fluxo de busca por produto via IA: 3º botão pra
        // voltar pro resto do cardápio da loja, já que o cliente entrou direto pelo produto
        // sem navegar. Só quando é 1 item só e ele veio marcado como `from_search`.
        if (count($addedItems) === 1 && ! empty($addedItems[0]['from_search']) && ! empty($cart['store_id'])) {
            $buttons[] = ['id' => 'view_menu_'.$cart['store_id'], 'label' => '📖 Cardápio da loja'];
        }

        try {
            $title = $this->brandedTitle($this->storeNameBySlug($cart['store_id'] ?? null));
            $this->zapiClient->sendButtonActions($phone, $message, $buttons, $title);
        } catch (\Throwable $exception) {
            Log::warning('Feedback acumulado falhou.', ['error' => $exception->getMessage()]);
        }
    }

    /**
     * "✅ X adicionado!" + linhas de complementos/adicionais (§6 Passo 3, §8.1 Passo 4,
     * §8.2 confirmação de combo).
     */
    private function singleItemFeedbackText(array $item): string
    {
        $lines = ['✅ '.$this->cartItemLabel($item).' adicionad'.$this->genderSuffix($item).'!'];

        if (! empty($item['is_combo'])) {
            foreach ($this->comboUnitsSummaryLines($item) as $line) {
                $lines[] = trim($line);
            }

            return implode("\n", $lines);
        }

        [$free, $paid] = $this->splitCustomizationsByCharge($item['customizations'] ?? []);

        if ($free !== []) {
            $lines[] = '🍌 Complementos: '.implode(', ', $free);
        }
        if ($paid !== []) {
            $lines[] = '➕ Adicionais: '.implode(', ', $paid);
        }

        return implode("\n", $lines);
    }

    /**
     * "adicionado" vs "adicionada" — heurística simples pelo final do nome do produto
     * (Pizza adicionada / Hambúrguer adicionado), como nos exemplos do documento.
     */
    private function genderSuffix(array $item): string
    {
        $name = mb_strtolower(trim((string) ($item['product_name'] ?? '')));

        return str_ends_with($name, 'a') ? 'a' : 'o';
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, string>} [labels grátis, labels pagos]
     */
    private function splitCustomizationsByCharge(array $customizations): array
    {
        $free = [];
        $paid = [];

        foreach ($customizations as $customization) {
            $label = (string) ($customization['label'] ?? '');
            if ($label === '') {
                continue;
            }

            if (($customization['charge_type'] ?? 'paid') === 'free' && (float) ($customization['price'] ?? 0) <= 0) {
                $free[] = $label;
            } else {
                $paid[] = $label;
            }
        }

        return [$free, $paid];
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

    /**
     * "Pizza Grande de Calabresa com Frango com Catupiry" — nome + tamanho + sabor extra
     * (quando existir). Usado em todo lugar que mostra a linha do carrinho pro cliente.
     */
    private function cartItemLabel(array $item): string
    {
        $label = (string) $item['product_name'];

        if (! empty($item['variation_name'])) {
            $label .= ' ('.$item['variation_name'].')';
        }

        if (! empty($item['extra_product_name'])) {
            $label .= ' + '.$item['extra_product_name'];
        }

        return $label;
    }

    /**
     * "Açaí 1 — Complementos: Banana, Granola | Adicional: Morango" por unidade — formato de
     * combo do §8.2, usado em vez do loop plano de `customizations` sempre que
     * `item['is_combo']` é true.
     */
    private function comboUnitsSummaryLines(array $item): array
    {
        $lines = [];

        foreach (($item['combo_units'] ?? []) as $index => $unit) {
            $label = (string) ($unit['label'] ?? 'Item');
            [$free, $paid] = $this->splitCustomizationsByCharge($unit['customizations'] ?? []);

            $parts = [];
            if ($free !== []) {
                $parts[] = 'Complementos: '.implode(', ', $free);
            }
            if ($paid !== []) {
                $parts[] = (count($paid) > 1 ? 'Adicionais: ' : 'Adicional: ').implode(', ', $paid);
            }

            $line = $label.' '.($index + 1);
            if ($parts !== []) {
                $line .= ' — '.implode(' | ', $parts);
            }

            $lines[] = $line;
        }

        return $lines;
    }

    private function normalizeCartItems(array $items): array
    {
        if (array_is_list($items)) {
            // New format: each element is an item array
            return array_values(array_filter(
                array_map(fn (mixed $item): ?array => is_array($item) ? [
                    'product_id'            => (int) ($item['product_id'] ?? 0),
                    'product_name'          => (string) ($item['product_name'] ?? 'Produto'),
                    'base_price'            => (float) ($item['base_price'] ?? 0.0),
                    'additional_price'      => (float) ($item['additional_price'] ?? 0.0),
                    'customizations'        => is_array($item['customizations'] ?? null) ? $item['customizations'] : [],
                    'customizations_total'  => (float) ($item['customizations_total'] ?? 0.0),
                    'quantity'              => max(1, (int) ($item['quantity'] ?? 1)),
                    'variation_id'          => isset($item['variation_id']) ? (int) $item['variation_id'] : null,
                    'variation_name'        => isset($item['variation_name']) ? (string) $item['variation_name'] : null,
                    'observation'           => isset($item['observation']) && $item['observation'] !== '' ? (string) $item['observation'] : null,
                    'extra_product_id'      => isset($item['extra_product_id']) ? (int) $item['extra_product_id'] : null,
                    'extra_product_name'    => isset($item['extra_product_name']) ? (string) $item['extra_product_name'] : null,
                    'is_combo'              => (bool) ($item['is_combo'] ?? false),
                    'combo_units'           => is_array($item['combo_units'] ?? null) ? $item['combo_units'] : [],
                    'from_search'           => (bool) ($item['from_search'] ?? false),
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
                'product_id'            => (int) $productId,
                'product_name'          => $product ? (string) $product->name : 'Produto #'.$productId,
                'base_price'            => $product ? (float) $product->price : 0.0,
                'additional_price'      => 0.0,
                'customizations'        => [],
                'customizations_total'  => 0.0,
                'quantity'              => (int) $qty,
                'variation_id'          => null,
                'variation_name'        => null,
                'observation'           => null,
            ];
        }

        return $normalized;
    }


    /**
     * §11 — carrinho no formato do documento. `$prefix` permite embutir a linha de remoção
     * ("🗑️ X removido!") na MESMA mensagem, como no exemplo do §11.1.
     */
    public function sendCartSummary(string $phone, string $prefix = ''): bool
    {
        $state = $this->flow->getState($phone);
        $cart = $state['cart'] ?? null;

        if (! is_array($cart) || ! is_array($cart['items'] ?? null) || $cart['items'] === []) {
            return $this->sendEmptyCartMessage($phone, $prefix);
        }

        $lines = $prefix !== '' ? [$prefix, ''] : [];
        $lines[] = '🛒 *Seu carrinho:*';
        $subtotal = 0.0;

        foreach ($this->normalizeCartItems($cart['items']) as $item) {
            $lineTotal = ($item['base_price'] + $item['additional_price'] + $item['customizations_total']) * $item['quantity'];
            $subtotal += $lineTotal;
            $label = $this->cartItemLabel($item);
            $lines[] = '• '.$item['quantity'].'x '.$label.' — R$ '.number_format($lineTotal, 2, ',', '.');
            if (! empty($item['is_combo'])) {
                foreach ($this->comboUnitsSummaryLines($item) as $line) {
                    $lines[] = '   '.$line;
                }
            } else {
                foreach ($item['customizations'] as $customization) {
                    $lines[] = '   ➕ '.($customization['label'] ?? '');
                }
            }
            if ($item['observation']) {
                $lines[] = '   📝 '.$item['observation'];
            }
        }

        $lines[] = '🍱 *Subtotal: R$ '.number_format($subtotal, 2, ',', '.').'*';

        try {
            $this->zapiClient->sendButtonActions(
                $phone,
                implode("\n", $lines),
                [
                    ['id' => 'flow_finalize_order', 'label' => '🛒 Finalizar Pedido'],
                    ['id' => 'flow_edit_cart', 'label' => '✏️ Editar Carrinho'],
                ],
                $this->brandedTitle($this->storeNameBySlug($cart['store_id'] ?? null))
            );

            return true;
        } catch (\Throwable $exception) {
            Log::warning('Failed to send cart summary.', ['phone' => $phone, 'error' => $exception->getMessage()]);

            return false;
        }
    }

    /**
     * §11.1 — carrinho vazio: volta pro cardápio da mesma loja ou reseta pro menu principal.
     */
    private function sendEmptyCartMessage(string $phone, string $prefix = ''): bool
    {
        $message = ($prefix !== '' ? $prefix."\n\n" : '').'🛒 Seu carrinho está vazio.';

        try {
            $this->zapiClient->sendButtonActions($phone, $message, [
                ['id' => 'cart_view_menu', 'label' => '🍔 Ver Cardápio'],
                ['id' => 'flow_home', 'label' => '🏠 Menu Principal'],
            ]);

            return true;
        } catch (\Throwable $exception) {
            Log::warning('Failed to send empty-cart response.', ['phone' => $phone, 'error' => $exception->getMessage()]);

            return false;
        }
    }
}
