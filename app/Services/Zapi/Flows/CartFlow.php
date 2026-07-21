<?php

namespace App\Services\Zapi\Flows;

// 1. Imports corrigidos (Problemas 3 e 4)
use App\Models\Store;
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

        if ($store !== null && $product !== null && $store->usesFlavorComboFlow() && $product->available_for_combo) {
            $hasOtherFlavors = $product->category_id !== null && Product::query()
                ->where('is_active', true)
                ->where('store_id', $store->id)
                ->where('category_id', $product->category_id)
                ->where('id', '!=', $product->id)
                ->where('available_for_combo', true)
                ->exists();

            if ($hasOtherFlavors) {
                $state = $this->getState($phone);
                $state['pending_add'] = [
                    'store_id'                   => $storeId,
                    'product_id'                 => $productId,
                    'variation_id'               => $variationId,
                    'variation_name'             => $variationName,
                    'variation_additional_price' => $variationAdditionalPrice,
                    'quantity'                   => $quantity,
                    'step'                       => 'extra_flavor_ask',
                ];
                $this->saveFlowState($phone, $state);

                $flavorLabel = $product->name.' ('.$variationName.')';

                try {
                    $this->zapiClient->sendButtonActions(
                        $phone,
                        "🍕 *{$flavorLabel}*\nDeseja adicionar mais algum sabor nessa pizza?",
                        [
                            ['id' => 'flow_pizza_extra_yes', 'label' => '✅ Sim'],
                            ['id' => 'flow_pizza_extra_no', 'label' => '❌ Não'],
                        ]
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

        if (! $wantsExtra) {
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

        $siblings = Product::query()
            ->where('is_active', true)
            ->where('store_id', $store->id)
            ->where('category_id', $product->category_id)
            ->where('id', '!=', $product->id)
            ->where('available_for_combo', true)
            ->orderBy('name')
            ->get();

        if ($siblings->isEmpty()) {
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
     * Sabor extra escolhido no carrossel: resolve o preço combinado (sempre o sabor mais
     * caro no mesmo tamanho já escolhido) e segue pro fluxo de borda/customização.
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

        $variationName = (string) $pending['variation_name'];

        $matchingVariation = $extraProduct->variations
            ->first(fn (ProductVariation $v): bool => (bool) $v->is_active
                && mb_strtolower(trim((string) $v->name)) === mb_strtolower(trim($variationName)));

        $firstFlavorEffectivePrice = (float) $pending['variation_additional_price']
            + (float) (Product::query()->where('id', (int) $pending['product_id'])->value('price') ?? 0);

        $extraFlavorEffectivePrice = (float) $extraProduct->price
            + ($matchingVariation !== null ? (float) $matchingVariation->additional_price : 0.0);

        if ($matchingVariation === null) {
            Log::warning('Sabor extra sem variação de tamanho correspondente — usando preço base.', [
                'extra_product_id' => $extraProduct->id,
                'variation_name'   => $variationName,
            ]);
        }

        $comboPrice = max($firstFlavorEffectivePrice, $extraFlavorEffectivePrice);

        $state['pending_add'] = array_merge($pending, [
            'step'                => null,
            'extra_product_id'    => $extraProduct->id,
            'extra_product_name'  => (string) $extraProduct->name,
            'combo_base_price'    => $comboPrice,
        ]);
        $this->saveFlowState($phone, $state);

        return $this->beginCustomizationOrCommit(
            $phone,
            (string) $pending['store_id'],
            (int) $pending['product_id'],
            (int) $pending['variation_id'],
            $variationName,
            0.0, // preço já embutido em combo_base_price
            (int) $pending['quantity'],
            $extraProduct->id,
            (string) $extraProduct->name,
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

        $product = Product::query()->where('is_active', true)->find($productId);
        $store = Store::query()->find($product?->store_id);
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
            return $this->commitAndSendFeedback(
                $phone, $storeId, $productId, $variationId, $variationName, $variationAdditionalPrice,
                $quantity, [], $extraProductId, $extraProductName, $overrideBasePrice
            );
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
                ]);

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

        if ($flowIds->isEmpty()) {
            return collect();
        }

        return OptionalFlowStep::query()
            ->whereIn('optional_flow_id', $flowIds)
            ->with(['options' => fn ($q) => $q->where('is_active', true)->orderBy('position')])
            ->orderBy('position')
            ->get()
            ->filter(fn (OptionalFlowStep $step): bool => $step->options->isNotEmpty())
            ->values();
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

        if ((int) $step->max_select <= 1) {
            return $this->sendCustomizationOptionPrompt($phone, $step);
        }

        if (! (bool) $step->is_required) {
            return $this->sendCustomizationAskPrompt($phone, $step);
        }

        return $this->sendCustomizationMultiSelectPrompt($phone, $step);
    }

    /**
     * Step obrigatório de seleção única (ex.: tamanho): mesma regra de ≤3 botões / >3 lista
     * já usada em sendVariationPrompt para variação de produto.
     */
    private function sendCustomizationOptionPrompt(string $phone, OptionalFlowStep $step): bool
    {
        $question = trim((string) ($step->customer_hint ?: $step->title));
        $options = $step->options;

        if ($options->count() > 3) {
            $rows = $options->map(fn (OptionalFlowStepOption $option): array => [
                'id'          => 'flow_custopt_'.$step->id.'_'.$option->id,
                'title'       => (string) $option->title,
                'description' => ((float) $option->price) > 0
                    ? '+R$ '.number_format((float) $option->price, 2, ',', '.')
                    : '',
            ])->values()->all();

            try {
                $this->zapiClient->sendList($phone, $question, 'Ver opções', $step->title, '', $rows);

                return true;
            } catch (\Throwable $exception) {
                Log::warning('Failed to send customization option list.', ['error' => $exception->getMessage()]);

                return false;
            }
        }

        $buttons = $options->map(fn (OptionalFlowStepOption $option): array => [
            'id'    => 'flow_custopt_'.$step->id.'_'.$option->id,
            'label' => $option->title.(
                ((float) $option->price) > 0
                    ? ' (+R$ '.number_format((float) $option->price, 2, ',', '.').')'
                    : ''
            ),
        ])->values()->all();

        try {
            $this->zapiClient->sendButtonActions($phone, $question, $buttons);

            return true;
        } catch (\Throwable $exception) {
            Log::warning('Failed to send customization option buttons.', ['error' => $exception->getMessage()]);

            return false;
        }
    }

    /**
     * Step opcional de múltipla seleção (ex.: adicionais): pergunta primeiro se o cliente
     * quer ver as opções (documento §6, Passo 1).
     */
    private function sendCustomizationAskPrompt(string $phone, OptionalFlowStep $step): bool
    {
        $message = trim((string) ($step->customer_hint ?: $step->title))."\nQuer colocar algum adicional?";

        try {
            $this->zapiClient->sendButtonActions($phone, $message, [
                ['id' => 'flow_custask_'.$step->id, 'label' => '➕ Sim, quero adicionais'],
                ['id' => 'flow_custskip_'.$step->id, 'label' => '🚫 Não, obrigado'],
            ]);

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
    private function sendCustomizationMultiSelectPrompt(string $phone, OptionalFlowStep $step): bool
    {
        $rows = $step->options->map(fn (OptionalFlowStepOption $option): array => [
            'id'          => 'flow_custopt_'.$step->id.'_'.$option->id,
            'title'       => (string) $option->title,
            'description' => ((float) $option->price) > 0
                ? '+R$ '.number_format((float) $option->price, 2, ',', '.')
                : '',
        ])->values()->all();

        try {
            $this->zapiClient->sendList(
                $phone,
                '➕ Escolha os adicionais que quiser (pode marcar mais de um):',
                'Ver adicionais',
                $step->title,
                '',
                $rows
            );

            return true;
        } catch (\Throwable $exception) {
            Log::warning('Failed to send customization multi-select list.', ['error' => $exception->getMessage()]);

            return false;
        }
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
            return $this->advanceCustomizationStep($phone);
        }

        $step = OptionalFlowStep::query()
            ->with(['options' => fn ($q) => $q->where('is_active', true)->orderBy('position')])
            ->find((int) $stepId);

        if ($step === null || $step->options->isEmpty()) {
            return $this->advanceCustomizationStep($phone);
        }

        return $this->sendCustomizationMultiSelectPrompt($phone, $step);
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
            return false;
        }

        $step = OptionalFlowStep::find((int) $stepId);

        if ($step === null) {
            return false;
        }

        $selections = $pending['selections'] ?? [];
        $stepSelections = $selections[(int) $stepId] ?? [];

        if (! in_array((int) $optionId, $stepSelections, true)) {
            $stepSelections[] = (int) $optionId;
        }

        $selections[(int) $stepId] = $stepSelections;
        $pending['selections'] = $selections;
        $state['pending_add'] = $pending;
        $this->saveFlowState($phone, $state);

        if ((int) $step->max_select <= 1) {
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
        $selections = $pending['selections'] ?? [];
        $optionIds = collect($selections)->flatten()->map(fn ($id): int => (int) $id)->unique()->values();

        $customizations = [];

        if ($optionIds->isNotEmpty()) {
            $options = OptionalFlowStepOption::query()->whereIn('id', $optionIds)->get()->keyBy('id');

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
                        'price'     => (float) $option->price,
                    ];
                }
            }
        }

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
            $customizations,
            isset($pending['extra_product_id']) ? (int) $pending['extra_product_id'] : null,
            $pending['extra_product_name'] ?? null,
            isset($pending['override_base_price']) ? (float) $pending['override_base_price'] : null
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
            $label = $this->cartItemLabel($item);
            $valorTotalItem = ($item['base_price'] + $item['additional_price'] + $item['customizations_total']) * $item['quantity'];

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
        int $quantity,
        array $customizations = [],
        ?int $extraProductId = null,
        ?string $extraProductName = null,
        ?float $overrideBasePrice = null
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

            $customizationsTotal = array_sum(array_map(
                static fn (array $customization): float => (float) ($customization['price'] ?? 0),
                $customizations
            ));

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
                'observation'           => null,
                'extra_product_id'      => $extraProductId,
                'extra_product_name'    => $extraProductName,
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
            $itemLabel = $this->cartItemLabel($item);
            $itemUnit  = ($item['base_price'] + $item['additional_price'] + $item['customizations_total']);
            $itemTotal = $itemUnit * $item['quantity'];
            $cartTotal += $itemTotal;
            $summaryLines[] = "- {$item['quantity']}x {$itemLabel}";

            foreach ($item['customizations'] as $customization) {
                $price = (float) ($customization['price'] ?? 0);
                $summaryLines[] = '  ➕ '.($customization['label'] ?? '').($price > 0 ? ' — R$ '.number_format($price, 2, ',', '.') : '');
            }
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
            $lineTotal = ($item['base_price'] + $item['additional_price'] + $item['customizations_total']) * $item['quantity'];
            $total += $lineTotal;
            $label = $this->cartItemLabel($item);
            $lines[] = $index.'. '.$item['quantity'].'x *'.$label.'* — R$ '.number_format($lineTotal, 2, ',', '.');
            foreach ($item['customizations'] as $customization) {
                $lines[] = '   ➕ '.($customization['label'] ?? '');
            }
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
