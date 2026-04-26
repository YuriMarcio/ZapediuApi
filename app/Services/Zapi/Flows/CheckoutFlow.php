<?php

namespace App\Services\Zapi\Flows;

use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use App\Models\UserAddress;
use App\Models\UserPhone;
use App\Services\Zapi\ZapiClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class CheckoutFlow
{
    // 1. Construtor com injeção de dependências
    public function __construct(
        private FlowManager $flow,
        private ZapiClient $zapiClient,
        private GreetingFlow $greetingFlow // Necessário para o sendWelcomePrompt
    ) {
    }

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

    // 5. Métodos "Emprestados" ou Stubs necessários
    private function sendWelcomePrompt(string $phone): bool
    {
        return $this->greetingFlow->sendWelcomePrompt($phone);
    }

    private function normalizeCartItems(array $items): array
    {
        // Reutilizando lógica do CartFlow ou implementando básica aqui
        return array_map(fn ($item) => [
            'product_id' => $item['product_id'] ?? 0,
            'product_name' => $item['product_name'] ?? 'Produto',
            'base_price' => (float) ($item['base_price'] ?? 0),
            'additional_price' => (float) ($item['additional_price'] ?? 0),
            'quantity' => (int) ($item['quantity'] ?? 1),
            'variation_name' => $item['variation_name'] ?? null,
            'observation' => $item['observation'] ?? null,
        ], $items);
    }

    private function buildStoreDeliveryFee(Store $store): float
    {
        $state = $this->flow->getState($this->currentPhone ?? '');
        $customer = $state['customer_coords'] ?? null; // Lat/Lng que o Google salvou

        if (!$customer) {
            return 9.0;
        }

        // Verifica se a loja e o cliente estão dentro da sua área cinza da imagem
        $lojaDentro = $this->isInsideGaleaoPolygon($store->latitude, $store->longitude);
        $clienteDentro = $this->isInsideGaleaoPolygon($customer['lat'], $customer['lng']);

        if ($lojaDentro && $clienteDentro) {
            return 8.0;
        }

        return 9.0;
    }

    private function isInsideGaleaoPolygon($lat, $lng): bool
    {
        // Coordenadas dos pontos que você marcou no mapa (exemplo aproximado)
        // Você deve pegar as coordenadas reais de cada ponto do seu desenho
        $polygon = [
            ['lat' => -22.805, 'lng' => -43.235], // Ponto superior esquerdo
            ['lat' => -22.800, 'lng' => -43.220], // Ponto superior direito
            ['lat' => -22.815, 'lng' => -43.210], // Ponto inferior direito
            ['lat' => -22.825, 'lng' => -43.225], // Ponto inferior esquerdo
            // ... adicione todos os pontos do contorno da imagem
        ];

        $vertices_count = count($polygon);
        $inside = false;

        for ($i = 0, $j = $vertices_count - 1; $i < $vertices_count; $j = $i++) {
            if (((($polygon[$i]['lat'] > $lat) != ($polygon[$j]['lat'] > $lat))) &&
                ($lng < ($polygon[$j]['lng'] - $polygon[$i]['lng']) * ($lat - $polygon[$i]['lat']) /
                ($polygon[$j]['lat'] - $polygon[$i]['lat']) + $polygon[$i]['lng'])) {
                $inside = !$inside;
            }
        }

        return $inside;
    }

    private function haversine($lat1, $lon1, $lat2, $lon2)
    {
        $earthRadius = 6371; // Raio da Terra em KM
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon / 2) * sin($dLon / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earthRadius * $c;
    }

    public function finalizeCart(string $phone): bool
    {
        $state = $this->flow->getState($phone);
        $cart = $state['cart'] ?? null;

        if (!is_array($cart) || empty($cart['items'])) {
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
            }

            $state['customer'] = $customer;
            $this->saveFlowState($phone, $state);

            Log::info('User found for checkout, sending address confirmation', ['phone' => $normalizedPhone, 'customer' => $customer]);

            return !empty($customer['address'])
                ? $this->sendAddressConfirmation($phone, $customer)
                : $this->startCheckoutDataCollection($phone);
        }

        return $this->startEmailVerificationForNewNumber($phone);
    }

    public function sendAddressConfirmation(string $phone, array $customer): bool
    {
        // ❌ Remova qualquer coisa parecida com: "👋 Olá, " . $customer['name'] . "!"
        Log::info('Sending address confirmation to '.$phone.' for customer: '.json_encode($customer));
        // ✅ Deixe a mensagem direto ao ponto:
        $reference = $customer['reference'] ?? '';
        $message = "📍 *Entrega será em:*\n" .
               "{$customer['address']}\n" .
               ($reference ? "📌 *Referência:* $reference\n\n" : "\n") .
               "Está correto?";

        try {
            // Remova a palavra "return" daqui
            $this->zapiClient->sendButtonActions($phone, $message, [
                ['id' => 'checkout_confirm_address', 'label' => '✅ Confirmar endereço'],
                ['id' => 'checkout_change_address', 'label' => '✏️ Alterar endereço'],
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('Erro ao enviar confirmação de endereço', ['error' => $e->getMessage()]);
            return false;
        }
    }

    private function startCheckoutDataCollection(string $phone): bool
    {
        $state = $this->flow->getState($phone);
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
        $state = $this->flow->getState($phone);
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
                'email'      => $emailToUse, // Chave única que estava dando erro
            ],
            [
                'name'     => $customerName !== '' ? $customerName : 'Cliente '.($customerPhone !== '' ? $customerPhone : $orderCode),
                'phone'    => $customerPhone !== '' ? $customerPhone : null,
                'password' => Str::random(32),
                'is_admin' => false,
                'role'     => 'customer',
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
            case 'verify_email_lookup':
                $email = strtolower(trim($rawText));

                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    try {
                        $this->zapiClient->sendText($phone, '⚠️ E-mail inválido. Digite um e-mail válido para continuar.');
                        return true;
                    } catch (\Throwable) {
                        return false;
                    }
                }

                $normalizedPhone = $this->normalizePhoneForLookup($phone);
                $existingUser = User::query()->where('email', $email)->first();

                if ($existingUser !== null) {
                    // Usuário existe: segue fluxo de verificação de código
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

                // Usuário não existe: segue fluxo de cadastro, NÃO volta ao início
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

                // Não volta ao início, segue para coleta de dados
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

    private function sendDataConfirmation(string $phone): bool
    {
        $state    = $this->flow->getState($phone);
        $customer = $state['customer'] ?? [];

        $name      = trim((string) ($customer['name']      ?? ''));
        $email     = trim((string) ($customer['email']     ?? ''));
        $address   = trim((string) ($customer['address']   ?? ''));
        $reference = trim((string) ($customer['reference'] ?? ''));

        $lines   = [];
        $lines[] = '✅ *Confirme seus dados de entrega:*';
        $lines[] = '';

        if ($name !== '') {
            $lines[] = '👤 *Nome:* '.$name;
        }
        if ($email !== '') {
            $lines[] = '📧 *E-mail:* '.$email;
        }
        if ($address !== '') {
            $lines[] = '📍 *Endereço:* '.$address;
        }
        if ($reference !== '') {
            $lines[] = '📌 *Referência:* '.$reference;
        }

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

    public function sendOrderSummary(string $phone): bool
    {
        $state   = $this->flow->getState($phone);
        $cart    = $state['cart'] ?? [];
        $storeId = (string) ($cart['store_id'] ?? '');
        $store   = Store::query()->where('slug', $storeId)->first();
        $customer = $state['customer'] ?? [];

        if ($store === null) {
            return false;
        }

        // Patch: Sempre usar e persistir user_id
        $customerUser = null;
        if (!empty($customer['user_id'])) {
            $customerUser = User::find($customer['user_id']);
        }
        if (!$customerUser) {
            $normalizedPhone = (string) ($customer['phone'] ?? '');
            $email = (string) ($customer['email'] ?? '');
            $customerUser = User::where('phone', $normalizedPhone)
                                ->orWhere('email', $email)
                                ->first();
            if (!$customerUser) {
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

        // 👇 A MÁGICA ACONTECE AQUI: Transforma o array em texto
        $message = implode("\n", $lines);

        try {
            // Removemos o "return" daqui
            $this->zapiClient->sendButtonActions($phone, $message, [
                ['id' => 'checkout_pay_now', 'label' => '💳 Pagar agora'],
                ['id' => 'flow_edit_cart',   'label' => '✏️ Editar pedido'],
            ]);

            // E colocamos o return true aqui!
            return true;
        } catch (\Throwable $e) {
            Log::error('Erro ao enviar resumo final', ['error' => $e->getMessage()]);
            return false;
        }
    }

    private function handleSummaryEdit(string $phone, string $rawText, string $normalizedText): bool
    {
        $state = $this->flow->getState($phone);
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

    public function processPayment(string $phone): bool
    {
        $state    = $this->flow->getState($phone);
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

        // Patch: Always use user_id from state if present
        $customerUser = null;
        if (!empty($customer['user_id'])) {
            $customerUser = \App\Models\User::find($customer['user_id']);
        }
        if (!$customerUser) {
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

        $msgLines   = [];
        $msgLines[] = 'Tudo certo com o seu pedido! ✅';
        $msgLines[] = '';
        $msgLines[] = '🧾 *Pedido:* ' . $orderCode;
        $msgLines[] = '💰 *Total a pagar:* ' . $amount;
        $msgLines[] = '';
        $msgLines[] = '🔒 _Aceitamos PIX ou Cartão em ambiente seguro._';
        $msgLines[] = '';
        $msgLines[] = 'É só clicar no botão abaixo. Assim que o pagamento for aprovado, te aviso aqui mesmo! 👇';

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
}
