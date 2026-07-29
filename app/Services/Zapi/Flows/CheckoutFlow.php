<?php

namespace App\Services\Zapi\Flows;

use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use App\Models\UserAddress;
use App\Models\UserPhone;
use App\Services\Delivery\DeliveryPricingService;
use App\Services\GeocodeService;
use App\Services\Payment\PaymentLinkBuilder;
use App\Services\Whatsapp\WhatsAppClientInterface;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class CheckoutFlow
{
    // 1. Construtor com injeção de dependências
    public function __construct(
        private FlowManager $flow,
        private WhatsAppClientInterface $zapiClient,
        private DeliveryPricingService $deliveryPricing,
        private PaymentLinkBuilder $paymentLinkBuilder,
    ) {}

    // 2. Auxiliar para salvar estado de forma consistente
    private function saveFlowState(string $phone, array $state): void
    {
        $this->flow->saveState($phone, $state);
    }

    // 3. Método de normalização de telefone que faltava
    private function normalizePhoneForLookup(string $phone): string
    {
        return preg_replace('/\D+/', '', Str::before($phone, '@'));
    }

    /**
     * Header padrão das mensagens de checkout: "Zapediu & {Loja}" — o carrinho é mono-loja
     * (§10), então a loja atual da sessão identifica sem ambiguidade qualquer etapa do
     * checkout (endereço, resumo, pagamento).
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

    private function cartStoreTitle(array $state): string
    {
        return $this->brandedTitle($this->storeNameBySlug($state['cart']['store_id'] ?? $state['selected_store_id'] ?? null));
    }

    // 5. Métodos "Emprestados" ou Stubs necessários
    private function sendWelcomePrompt(string $phone): bool
    {
        $greetingFlow = App::make(GreetingFlow::class);

        return $greetingFlow->sendWelcomePrompt($phone);
    }

    private function normalizeCartItems(array $items): array
    {
        // Reutilizando lógica do CartFlow ou implementando básica aqui
        return array_map(fn ($item) => [
            'product_id' => $item['product_id'] ?? 0,
            'product_name' => $item['product_name'] ?? 'Produto',
            'base_price' => (float) ($item['base_price'] ?? 0),
            'additional_price' => (float) ($item['additional_price'] ?? 0),
            'customizations' => is_array($item['customizations'] ?? null) ? $item['customizations'] : [],
            'customizations_total' => (float) ($item['customizations_total'] ?? 0),
            'quantity' => (int) ($item['quantity'] ?? 1),
            'variation_name' => $item['variation_name'] ?? null,
            'observation' => $item['observation'] ?? null,
            'extra_product_id' => $item['extra_product_id'] ?? null,
            'extra_product_name' => $item['extra_product_name'] ?? null,
            'is_combo' => (bool) ($item['is_combo'] ?? false),
            'combo_units' => is_array($item['combo_units'] ?? null) ? $item['combo_units'] : [],
        ], $items);
    }

    /**
     * Espelha CartFlow::comboUnitsSummaryLines — "Açaí 1 — Complementos: Banana | Adicional:
     * Morango" (§8.2), usado no resumo final do pedido.
     */
    private function comboUnitsSummaryLines(array $item): array
    {
        $lines = [];

        foreach (($item['combo_units'] ?? []) as $index => $unit) {
            $label = (string) ($unit['label'] ?? 'Item');
            $free = [];
            $paid = [];

            foreach (($unit['customizations'] ?? []) as $customization) {
                $optionLabel = (string) ($customization['label'] ?? '');
                if ($optionLabel === '') {
                    continue;
                }

                if (($customization['charge_type'] ?? 'paid') === 'free' && (float) ($customization['price'] ?? 0) <= 0) {
                    $free[] = $optionLabel;
                } else {
                    $paid[] = $optionLabel;
                }
            }

            $parts = [];
            if ($free !== []) {
                $parts[] = 'Complementos: '.implode(', ', $free);
            }
            if ($paid !== []) {
                $parts[] = (count($paid) > 1 ? 'Adicionais: ' : 'Adicional: ').implode(', ', $paid);
            }

            $line = '   '.$label.' '.($index + 1);
            if ($parts !== []) {
                $line .= ' — '.implode(' | ', $parts);
            }

            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * "Pizza Grande de Calabresa com Frango com Catupiry" — nome + tamanho + sabor extra
     * (quando existir). Espelha CartFlow::cartItemLabel pro texto do pedido bater com o do
     * carrinho.
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

    private function customerCoords(array $state): ?array
    {
        $coords = $state['customer_coords'] ?? null;

        if (! is_array($coords) || ! isset($coords['lat'], $coords['lng'])) {
            return null;
        }

        return ['lat' => (float) $coords['lat'], 'lng' => (float) $coords['lng']];
    }

    private function sendOutOfDeliveryAreaMessage(string $phone, array $state): bool
    {
        $state['checkout_step'] = 'change_address';
        $this->saveFlowState($phone, $state);

        try {
            $this->zapiClient->sendButtonActions(
                $phone,
                "😔 Poxa, sua localização ainda não é atendida por essa loja.\n\nVocê pode tentar outro endereço.",
                [['id' => 'checkout_change_address', 'label' => '✏️ Tentar Outro Endereço']],
                $this->cartStoreTitle($state)
            );

            return true;
        } catch (\Throwable $e) {
            Log::error('Erro ao enviar aviso de área fora de alcance', ['error' => $e->getMessage()]);

            return false;
        }
    }

    public function finalizeCart(string $phone): bool
    {
        $state = $this->flow->getState($phone);
        $cart = $state['cart'] ?? null;

        if (! is_array($cart) || empty($cart['items'])) {
            try {
                $this->zapiClient->sendText($phone, '🛒 Seu carrinho está vazio. Adicione produtos antes de finalizar.');

                return true;
            } catch (\Throwable $e) {
                Log::warning('Failed to send finalize-empty-cart', ['error' => $e->getMessage()]);

                return false;
            }
        }

        $normalizedPhone = $this->normalizePhoneForLookup($phone);
        $user = User::query()
            ->where('phone', $normalizedPhone)
            ->orWhereHas('phones', fn ($q) => $q->where('phone', $normalizedPhone))
            ->with(['primaryAddress'])
            ->first();

        Log::info('User lookup for checkout', ['phone' => $normalizedPhone, 'user_found' => $user !== null]);

        if ($user !== null) {
            $customer = (array) ($state['customer'] ?? []);
            $customer['name'] = $customer['name'] ?? $user->name;
            $customer['email'] = $customer['email'] ?? $user->email;
            $customer['phone'] = $normalizedPhone;

            if (empty($customer['address']) && $user->primaryAddress) {
                $customer['address'] = $user->primaryAddress->formatted;
                $customer['reference'] = $user->primaryAddress->notes;

                if ($user->primaryAddress->latitude !== null && $user->primaryAddress->longitude !== null) {
                    $state['customer_coords'] = [
                        'lat' => (float) $user->primaryAddress->latitude,
                        'lng' => (float) $user->primaryAddress->longitude,
                        'formatted' => $user->primaryAddress->formatted,
                    ];
                }
            }

            $state['customer'] = $customer;
            $this->saveFlowState($phone, $state);

            Log::info('User found for checkout, sending address confirmation', ['phone' => $normalizedPhone, 'customer' => $customer]);

            return ! empty($customer['address'])
                ? $this->sendAddressConfirmation($phone, $customer)
                : $this->startNewCustomerAddressFlow($phone, false);
        }

        return $this->startNewCustomerAddressFlow($phone, true);
    }

    /**
     * §12.1 Passo 1 — boas-vindas + pedido de endereço. Sem etapa de e-mail: o fluxo do
     * documento é endereço → nome → resumo. Botão nativo de localização fica pra quando o
     * gateway (FlowBridge) expuser Location Request Message — por ora só texto.
     */
    private function startNewCustomerAddressFlow(string $phone, bool $isFirstOrder): bool
    {
        $state = $this->flow->getState($phone);
        $state['checkout_step'] = 'new_address';
        $state['customer']['phone'] = $this->normalizePhoneForLookup($phone);
        $this->saveFlowState($phone, $state);

        $message = $isFirstOrder
            ? "🎉 Que bom te ver por aqui! Esse é seu primeiro pedido no Zapediu.\n\n"
                ."Pra eu confirmar sua entrega, me manda seu endereço completo (com CEP):\n\n"
                .'📍 Exemplo: Rua das Flores, 123 - Bairro Centro - CEP 65000-000'
            : "📍 Pra eu confirmar sua entrega, me manda seu endereço completo (com CEP):\n\n"
                .'📍 Exemplo: Rua das Flores, 123 - Bairro Centro - CEP 65000-000';

        try {
            $this->zapiClient->sendText($phone, $message);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Botão "✏️ Alterar endereço" (confirmação de endereço) e "✏️ Tentar Outro Endereço"
     * (área fora de alcance) convergem aqui — ambos só precisam pedir o novo texto;
     * `handleCheckoutTextInput` já trata o step `change_address`.
     */
    public function promptChangeAddress(string $phone): bool
    {
        $state = $this->flow->getState($phone);
        $state['checkout_step'] = 'change_address';
        $this->saveFlowState($phone, $state);

        try {
            $this->zapiClient->sendText(
                $phone,
                "📍 Sem problemas! Me informe o novo endereço completo de entrega:\n_Ex: Rua das Flores, 123 - Centro, Cidade - UF_"
            );

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * §12.2 — cliente cadastrado: endereço + nome mais recentes da base, com os três botões
     * do documento.
     */
    public function sendAddressConfirmation(string $phone, array $customer): bool
    {
        Log::info('Sending address confirmation to '.$phone.' for customer: '.json_encode($customer));

        $firstName = trim((string) Str::of((string) ($customer['name'] ?? ''))->before(' '));
        $reference = trim((string) ($customer['reference'] ?? ''));

        $message = '📍 Confirmando sua entrega'.($firstName !== '' ? ", {$firstName}" : '').":\n"
            .$customer['address']
            .($reference !== '' ? "\n📌 {$reference}" : '');

        try {
            $this->zapiClient->sendButtonActions($phone, $message, [
                ['id' => 'checkout_confirm_address', 'label' => '✅ Confirmar'],
                ['id' => 'checkout_list_addresses', 'label' => '📋 Ver Endereços'],
                ['id' => 'checkout_change_name', 'label' => '✏️ Mudar Nome'],
            ], $this->cartStoreTitle($this->flow->getState($phone)));

            return true;
        } catch (\Throwable $e) {
            Log::error('Erro ao enviar confirmação de endereço', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * §12.2 "📋 Ver Endereços" — botões com os últimos endereços do cliente (até 3, limite do WhatsApp).
     */
    public function sendAddressList(string $phone): bool
    {
        $normalizedPhone = $this->normalizePhoneForLookup($phone);
        $user = User::query()
            ->where('phone', $normalizedPhone)
            ->orWhereHas('phones', fn ($q) => $q->where('phone', $normalizedPhone))
            ->first();

        $addresses = $user === null
            ? collect()
            : UserAddress::query()
                ->where('user_id', $user->id)
                ->orderByDesc('is_primary')
                ->orderByDesc('updated_at')
                ->limit(3)
                ->get();

        if ($addresses->isEmpty()) {
            return $this->startNewCustomerAddressFlow($phone, false);
        }

        // Botões WhatsApp: limite de 3 por mensagem — endereços além do 3º ficam de fora.
        $buttons = $addresses->map(fn (UserAddress $address): array => [
            'id'    => 'checkout_addr_'.$address->id,
            'label' => mb_substr((string) ($address->formatted ?: $address->street), 0, 24),
        ])->values()->all();

        try {
            $title = $this->cartStoreTitle($this->flow->getState($phone));
            $this->zapiClient->sendButtonActions($phone, '📋 Escolha o endereço de entrega:', $buttons, $title);

            return true;
        } catch (\Throwable $e) {
            Log::warning('Failed to send address buttons.', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * §12.2 — endereço escolhido na lista: pula direto pro resumo, sem nova confirmação
     * (o clique na lista JÁ é a escolha explícita).
     */
    public function handleAddressSelected(string $phone, int $addressId): bool
    {
        $normalizedPhone = $this->normalizePhoneForLookup($phone);
        $address = UserAddress::query()
            ->whereKey($addressId)
            ->whereHas('user', function ($q) use ($normalizedPhone): void {
                $q->where('phone', $normalizedPhone)
                    ->orWhereHas('phones', fn ($q2) => $q2->where('phone', $normalizedPhone));
            })
            ->first();

        if ($address === null) {
            return $this->sendAddressList($phone);
        }

        $state = $this->flow->getState($phone);
        $state['customer']['address'] = (string) ($address->formatted ?: $address->street);
        $state['customer']['reference'] = $address->notes;

        if ($address->latitude !== null && $address->longitude !== null) {
            $state['customer_coords'] = [
                'lat' => (float) $address->latitude,
                'lng' => (float) $address->longitude,
                'formatted' => (string) ($address->formatted ?: $address->street),
            ];
        } else {
            unset($state['customer_coords']);
        }

        $state['checkout_step'] = '';
        $this->saveFlowState($phone, $state);

        return $this->sendOrderSummary($phone);
    }

    /**
     * §12.2 "✏️ Mudar Nome" — pede o nome e volta pra confirmação de entrega.
     */
    public function promptChangeName(string $phone): bool
    {
        $state = $this->flow->getState($phone);
        $state['checkout_step'] = 'change_name';
        $this->saveFlowState($phone, $state);

        try {
            $this->zapiClient->sendText($phone, '👤 Beleza! Qual nome você quer que eu use nesse pedido?');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function resolveCustomerUser(?int $companyId, string $customerName, string $customerPhone, string $customerEmail, string $orderCode): ?User
    {
        if ($customerPhone === '' && $customerEmail === '') {
            return null;
        }

        // Geramos o e-mail fallback caso ele venha vazio
        $emailToUse = $customerEmail !== ''
            ? $customerEmail
            : 'cliente-'.($customerPhone !== '' ? $customerPhone : Str::lower(Str::slug($orderCode))).'@deliveryzap.local';

        // O segredo está aqui: o updateOrCreate lida com a concorrência
        return User::updateOrCreate(
            [
                'company_id' => $companyId,
                'email' => $emailToUse, // Chave única que estava dando erro
            ],
            [
                'name' => $customerName !== '' ? $customerName : 'Cliente '.($customerPhone !== '' ? $customerPhone : $orderCode),
                'phone' => $customerPhone !== '' ? $customerPhone : null,
                'password' => Str::random(32),
                'is_admin' => false,
                'role' => 'customer',
            ]
        );
    }

    public function handleCheckoutTextInput(string $phone, string $rawText, string $normalizedText, string $checkoutStep): bool
    {
        $state = $this->flow->getState($phone);

        // Allow escape words to cancel checkout
        if (in_array($normalizedText, ['cancelar', 'voltar', 'inicio', 'menu', 'limpar'], true)) {
            $state['checkout_step'] = '';
            $this->saveFlowState($phone, $state);

            return $this->sendWelcomePrompt($phone);
        }

        switch ($checkoutStep) {
            // §12.1 Passo 1.b — cliente digitou o endereço: captura, geocodifica e segue
            // direto pro nome (sem confirmação extra nem pedido de complemento).
            case 'new_address':
            case 'change_address':
                $state['customer']['address'] = trim($rawText);

                // Tenta geocodificar o endereço (lat/long salvos junto — é o que a
                // loja/entregador usa pra rota, §12.1 "Pontos importantes").
                try {
                    $coords = GeocodeService::getCoordinates($state['customer']['address']);
                    if ($coords && isset($coords['latitude'], $coords['longitude'])) {
                        $state['customer_coords'] = [
                            'lat' => $coords['latitude'],
                            'lng' => $coords['longitude'],
                            'formatted' => $coords['formatted'] ?? $state['customer']['address'],
                        ];
                    } else {
                        unset($state['customer_coords']); // Remove se não conseguiu
                    }
                } catch (\Throwable $e) {
                    unset($state['customer_coords']);
                }

                if ($checkoutStep === 'change_address') {
                    $state['checkout_step'] = '';
                    $this->saveFlowState($phone, $state);

                    try {
                        $this->zapiClient->sendText($phone, '✅ Endereço atualizado!');
                    } catch (\Throwable) {
                    }

                    return $this->sendOrderSummary($phone);
                }

                // Cliente novo: se o nome já é conhecido, vai direto pro resumo.
                if (trim((string) ($state['customer']['name'] ?? '')) !== '') {
                    $state['checkout_step'] = '';
                    $this->saveFlowState($phone, $state);

                    return $this->sendOrderSummary($phone);
                }

                $state['checkout_step'] = 'new_name';
                $this->saveFlowState($phone, $state);

                try {
                    $this->zapiClient->sendText(
                        $phone,
                        '👤 Perfeito! E qual nome eu anoto no pedido, pra eu chamar você quando o entregador chegar?'
                    );

                    return true;
                } catch (\Throwable) {
                    return false;
                }

            // §12.1 Passo 2 — nome anotado → resumo completo (§13).
            case 'new_name':
                $state['customer']['name'] = trim($rawText);
                $state['checkout_step'] = '';
                $this->saveFlowState($phone, $state);

                return $this->sendOrderSummary($phone);

            // §12.2 "✏️ Mudar Nome" — atualiza e volta pra confirmação de entrega.
            case 'change_name':
                $state['customer']['name'] = trim($rawText);
                $state['checkout_step'] = '';
                $this->saveFlowState($phone, $state);

                return $this->sendAddressConfirmation($phone, (array) ($state['customer'] ?? []));

            case 'checkout_summary':
                return $this->handleSummaryEdit($phone, $rawText, $normalizedText);
        }

        return false;
    }

    public function sendOrderSummary(string $phone): bool
    {
        $state = $this->flow->getState($phone);
        $cart = $state['cart'] ?? [];
        $storeId = (string) ($cart['store_id'] ?? '');
        $store = Store::query()->where('slug', $storeId)->first();
        $customer = $state['customer'] ?? [];

        if ($store === null) {
            return false;
        }

        $coords = $this->customerCoords($state);
        if ($coords !== null && ! $this->deliveryPricing->isWithinDeliveryArea($store, $coords['lat'], $coords['lng'])) {
            return $this->sendOutOfDeliveryAreaMessage($phone, $state);
        }

        // Patch: Sempre usar e persistir user_id
        $customerUser = null;
        if (! empty($customer['user_id'])) {
            $customerUser = User::find($customer['user_id']);
        }
        if (! $customerUser) {
            $normalizedPhone = (string) ($customer['phone'] ?? '');
            $email = (string) ($customer['email'] ?? '');
            $customerUser = User::where('phone', $normalizedPhone)
                ->orWhere('email', $email)
                ->first();
            if (! $customerUser) {
                $customerUser = $this->resolveCustomerUser(
                    $store?->company_id,
                    (string) ($customer['name'] ?? ''),
                    $normalizedPhone,
                    $email,
                    'checkout-preview'
                );
            }
            // Salva o user_id no estado para travar futuras execuções
            $state['customer']['user_id'] = $customerUser?->id;
            $this->saveFlowState($phone, $state);
        }
        $this->syncUserAddress(
            $customerUser,
            (string) ($customer['address'] ?? ''),
            (string) ($customer['reference'] ?? '')
        );

        // Código do pedido já aparece no resumo (§13) — gerado aqui e reutilizado na criação
        // do pedido em processPayment, pra ser o MESMO número que o cliente vê depois.
        $orderCode = (string) ($state['pending_order_code'] ?? '');
        if ($orderCode === '') {
            $orderCode = 'ZAP-'.date('ymd').'-'.strtoupper(Str::random(4));
            $state['pending_order_code'] = $orderCode;
        }

        $state['checkout_step'] = 'checkout_summary';
        $this->saveFlowState($phone, $state);

        // Sem etapa intermediária de "Pagar Agora": o resumo já sai com o pedido criado e o
        // link real de pagamento (processPayment reaproveita o pending_order_code e o
        // customer.user_id que acabamos de salvar no state).
        return $this->processPayment($phone);
    }

    /**
     * Corpo do "🧾 Resumo do Pedido" (§13) — usado pela mensagem final de `processPayment`
     * (sendOrderSummary delega direto pra lá, sem etapa intermediária de "Pagar Agora"): o
     * resumo já sai com o pedido criado e o botão "Abrir link de pagamento" real.
     *
     * @param array<int, array<string, mixed>> $items
     */
    private function buildOrderSummaryLines(array $items, string $orderCode, string $address, string $reference, float $deliveryFee, float $total): array
    {
        $lines = [];
        $lines[] = '🧾 *Resumo do Pedido: #'.$orderCode.'*';

        foreach ($items as $item) {
            $label = $this->cartItemLabel($item);
            $line = '• '.$label.' ('.$item['quantity'].'x)';
            if (! empty($item['is_combo'])) {
                foreach ($this->comboUnitsSummaryLines($item) as $comboLine) {
                    $line .= "\n".$comboLine;
                }
            } else {
                foreach ($item['customizations'] as $customization) {
                    $line .= "\n   ➕ ".($customization['label'] ?? '');
                }
            }
            if ($item['observation']) {
                $line .= "\n   📝 ".$item['observation'];
            }
            $lines[] = $line;
        }

        $lines[] = '';
        $lines[] = '📍 *Entrega em:* '.($address ?: '—').($reference !== '' ? ' — '.$reference : '');
        $lines[] = '🛵 *Taxa de entrega:* '.($deliveryFee > 0 ? 'R$ '.number_format($deliveryFee, 2, ',', '.') : 'Grátis');
        $lines[] = '💰 *Total: R$ '.number_format($total, 2, ',', '.').'*';
        $lines[] = '⏳ *Tempo estimado:* 40-50 min';

        return $lines;
    }

    private function handleSummaryEdit(string $phone, string $rawText, string $normalizedText): bool
    {
        $state = $this->flow->getState($phone);
        $patterns = [
            'name' => '/^nome\s*:\s*(.+)$/iu',
            'address' => '/^endere[cç]o\s*:\s*(.+)$/iu',
            'reference' => '/^refer[eê]ncia\s*:\s*(.+)$/iu',
            'email' => '/^e?-?mail\s*:\s*(.+)$/iu',
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

    public function processPayment(string $phone): bool
    {
        $state = $this->flow->getState($phone);
        $cart = $state['cart'] ?? [];
        $storeId = (string) ($cart['store_id'] ?? '');
        $customer = $state['customer'] ?? [];

        if ($storeId === '' || empty($cart['items'])) {
            try {
                $this->zapiClient->sendText($phone, '🛒 Seu carrinho está vazio. Adicione produtos para finalizar.');

                return true;
            } catch (\Throwable) {
                return false;
            }
        }

        $store = Store::query()->visibleOnWhatsapp()->where('slug', $storeId)->first();

        if ($store === null) {
            try {
                $this->zapiClient->sendText(
                    $phone,
                    "Esta loja não está disponível no momento.\n\nEscolha outra loja para continuar seu pedido."
                );
            } catch (\Throwable) {
                // best-effort
            }

            return true;
        }

        $coords = $this->customerCoords($state);

        if ($store instanceof Store && $coords !== null && ! $this->deliveryPricing->isWithinDeliveryArea($store, $coords['lat'], $coords['lng'])) {
            return $this->sendOutOfDeliveryAreaMessage($phone, $state);
        }

        $items = $this->normalizeCartItems($cart['items']);
        $subtotal = 0.0;
        foreach ($items as $item) {
            $subtotal += ($item['base_price'] + $item['additional_price'] + $item['customizations_total']) * $item['quantity'];
        }
        $deliveryFee = $store instanceof Store
            ? $this->deliveryPricing->calculateFee($store, $coords['lat'] ?? null, $coords['lng'] ?? null)
            : 0.0;
        $total = $subtotal + $deliveryFee;

        // Reusa o código já mostrado no resumo (§13) pra ser o mesmo número em toda a conversa.
        $orderCode = (string) ($state['pending_order_code'] ?? '');
        if ($orderCode === '' || Order::query()->where('code', $orderCode)->exists()) {
            $orderCode = 'ZAP-'.date('ymd').'-'.strtoupper(Str::random(4));
        }
        unset($state['pending_order_code']);

        // Patch: Always use user_id from state if present
        $customerUser = null;
        if (! empty($customer['user_id'])) {
            $customerUser = User::find($customer['user_id']);
        }
        if (! $customerUser) {
            // Fallback: resolve or create user
            $customerUser = $this->resolveCustomerUser(
                $store?->company_id,
                (string) ($customer['name'] ?? ''),
                $this->normalizePhoneForLookup($phone),
                (string) ($customer['email'] ?? ''),
                $orderCode,
            );
            // Save user_id in state for future steps
            $state['customer']['user_id'] = $customerUser?->id;
            $this->saveFlowState($phone, $state);
        }

        Log::info('testando se passa aqui ');

        // Gera token público de checkout
        $publicToken = \Str::random(32);
        $rawPayload = [
            'cart' => $cart,
            'customer' => $customer,
            'checkout' => ['public_token' => $publicToken],
            'order_code' => $orderCode,
        ];

        Log::info('Creating order with code '.$orderCode, ['store' => $store?->toArray()]);

        $order = Order::query()->create([
            'code' => $orderCode,
            'code_confirm' => strtoupper(Str::random(5)),
            'user_id' => $customerUser?->id,
            'company_id' => $store?->company_id,
            'store_id' => $store?->id,
            'product_ids' => array_values(array_unique(array_merge(
                array_map(static fn (array $item): int => (int) $item['product_id'], $items),
                array_values(array_filter(array_map(static fn (array $item): ?int => $item['extra_product_id'] ?? null, $items)))
            ))),
            'status' => 'pending',
            'payment_status' => 'pending',
            'notes' => (string) ($customer['reference'] ?? ''),
            'subtotal' => $subtotal,
            'delivery_fee' => $deliveryFee,
            'total' => $total,
            'ordered_at' => now(),
            'raw_payload' => $rawPayload,
        ]);

        $this->syncUserPhone($customerUser, $this->normalizePhoneForLookup($phone));
        $this->syncUserAddress(
            $customerUser,
            (string) ($customer['address'] ?? ''),
            (string) ($customer['reference'] ?? '')
        );

        $paymentLink = $this->buildPaymentLink($phone, $storeId, $cart['items'], $total, $orderCode);

        // Pedido completo (mesmo formato do resumo §13) já com o link real — sem mensagem
        // enxuta separada só com código/total.
        $msgLines = $this->buildOrderSummaryLines(
            $items,
            $orderCode,
            (string) ($customer['address'] ?? ''),
            (string) ($customer['reference'] ?? ''),
            $deliveryFee,
            $total
        );

        try {
            $this->zapiClient->sendButtonActions(
                $phone,
                implode("\n", $msgLines),
                [['type' => 'URL', 'url' => $paymentLink, 'label' => '🔗 Abrir link de pagamento']],
                $this->brandedTitle($store?->name)
            );

            // Clear cart, persist order reference in state
            $state['last_order_code'] = $orderCode;
            $state['last_order_id'] = $order->id;
            $state['last_checkout_amount'] = $total;
            $state['last_checkout_at'] = now()->toIso8601String();
            $state['last_payment_link'] = $paymentLink;

            // Save active order for re-entry protection
            $state['active_order'] = [
                'order_id' => $order->id,
                'order_code' => $orderCode,
                'status' => 'pending',
                'payment_status' => 'pending',
                'payment_link' => $paymentLink,
                'created_at' => now()->toIso8601String(),
                'cart_snapshot' => $cart, // Preserve cart snapshot for potential reuse
            ];

            $state['cart'] = ['store_id' => $storeId, 'items' => []];
            $state['checkout_step'] = '';
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
     * Delegador público — mantido como método de instância porque `ButtonHandler` e outros
     * pontos do fluxo já chamam via `$this->checkoutFlow->buildPaymentLink(...)`. A lógica
     * real vive em `PaymentLinkBuilder`, reusada pelo lembrete de pagamento pendente (§13.2).
     */
    public function buildPaymentLink(string $phone, string $storeId, array $items, float $total, ?string $orderCode = null): string
    {
        $order = $orderCode ? Order::where('code', $orderCode)->first() : null;

        if ($order !== null) {
            return $this->paymentLinkBuilder->forOrder($order);
        }

        return $this->paymentLinkBuilder->forOrderCode($orderCode);
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

    /**
     * Check if user has active order and redirect accordingly
     * Returns true if redirected, false if no active order
     */
    public function checkActiveOrderRedirect(string $phone): bool
    {
        $state = $this->flow->getState($phone);

        if (empty($state['active_order'])) {
            return false; // No active order, normal flow
        }

        $orderData = $state['active_order'];
        $order = Order::find($orderData['order_id']);

        if (! $order) {
            // Order doesn't exist anymore, clear state
            unset($state['active_order']);
            $this->saveFlowState($phone, $state);

            return false;
        }

        // Check order status and redirect
        if ($order->statusValue() === 'pending' && $order->payment_status !== 'paid') {
            // Pending payment order
            return $this->sendPendingOrderMessage($phone, $order, $orderData['payment_link']);
        } elseif ($order->statusValue() === 'pending' && $order->payment_status === 'paid') {
            // Paid order, being prepared
            return $this->sendPaidOrderMessage($phone, $order);
        } elseif (in_array($order->status, ['preparing', 'delivering'])) {
            // Order in progress
            return $this->sendInProgressOrderMessage($phone, $order);
        } elseif (in_array($order->status, ['done', 'delivered', 'cancelled'])) {
            // Order completed or cancelled, clear active order
            unset($state['active_order']);
            $this->saveFlowState($phone, $state);

            return false;
        }

        return false;
    }

    /**
     * Send message for pending payment order
     */
    public function sendPendingOrderMessage(string $phone, Order $order, string $paymentLink): bool
    {
        $message = "📋 *Você tem um pedido pendente!*\n\n";
        $message .= "🧾 Pedido: #{$order->code}\n";
        $message .= '💰 Valor: R$ '.number_format($order->total, 2, ',', '.')."\n";
        $message .= '⏰ Criado: '.$order->created_at->format('d/m H:i')."\n\n";
        $message .= 'Deseja finalizar este pedido ou fazer um novo?';

        $buttons = [
            ['id' => 'order_resume_'.$order->id, 'label' => '🔗 Retomar Pagamento'],
            ['id' => 'order_new_'.$order->id, 'label' => '🛒 Fazer Novo Pedido'],
            ['id' => 'order_cancel_'.$order->id, 'label' => '❌ Cancelar Pedido'],
        ];

        try {
            $this->zapiClient->sendButtonActions($phone, $message, $buttons, $this->brandedTitle($order->store?->name));

            return true;
        } catch (\Throwable $e) {
            Log::error('Failed to send pending order message', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Send message for paid order (being prepared)
     */
    private function sendPaidOrderMessage(string $phone, Order $order): bool
    {
        $message = "🎉 *Seu pedido está sendo preparado!*\n\n";
        $message .= "🧾 Pedido: #{$order->code}\n";
        $message .= '💰 Valor pago: R$ '.number_format($order->total, 2, ',', '.')."\n";
        $message .= '⏰ Status: '.$this->getStatusDescription($order->statusValue())."\n\n";
        $message .= 'Seu pedido já está na cozinha! Em breve estará a caminho. 🍔🛵';

        try {
            $this->zapiClient->sendText($phone, $message);

            return true;
        } catch (\Throwable $e) {
            Log::error('Failed to send paid order message', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Send message for order in progress (preparing or delivering)
     */
    private function sendInProgressOrderMessage(string $phone, Order $order): bool
    {
        $statusMap = [
            'preparing' => '🏃‍♂️ Em preparação',
            'delivering' => '🛵 Saiu para entrega',
        ];

        $statusDesc = $statusMap[$order->status] ?? 'Em andamento';

        $message = "📦 *Seu pedido está a caminho!*\n\n";
        $message .= "🧾 Pedido: #{$order->code}\n";
        $message .= "📍 Status: {$statusDesc}\n";

        if ($order->statusValue() === 'delivering' && $order->courier) {
            $message .= '🚚 Entregador: '.$order->courier->name."\n";
        }

        $message .= "\nAcompanhe pelo painel ou aguarde a chegada!";

        try {
            $this->zapiClient->sendText($phone, $message);

            return true;
        } catch (\Throwable $e) {
            Log::error('Failed to send in-progress order message', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Get human-readable status description
     */
    private function getStatusDescription(string $status): string
    {
        $descriptions = [
            'pending' => '⏳ Aguardando pagamento',
            'accepted' => '✅ Aceito pela loja',
            'preparing' => '👨‍🍳 Em preparação',
            'preparToDelivery' => '📦 Pronto para entrega',
            'delivering' => '🛵 Saiu para entrega',
            'done' => '🎉 Entregue',
            'cancelled' => '❌ Cancelado',
        ];

        return $descriptions[$status] ?? $status;
    }
}
