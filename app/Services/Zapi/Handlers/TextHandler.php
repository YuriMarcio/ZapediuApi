<?php

namespace App\Services\Zapi\Handlers;

use App\Services\Zapi\Flows\FlowManager;
use App\Services\Zapi\Flows\GreetingFlow;
use App\Services\Zapi\Handlers\StoreHandle;
use App\Services\Zapi\ZapiClient;
use Illuminate\Support\Facades\Log;

class TextHandler
{
    public function __construct(
        private FlowManager $flow,
        private GreetingFlow $greetingFlow,
        private StoreHandle $storeHandle,
        private ZapiClient $zapiClient
    ) {
    }

    public function handle(string $phone, string $messageText): bool
    {

        $normalized = $this->flow->normalize($messageText);
        $state = $this->flow->getState($phone);

        // Comandos globais
        if ($normalized === 'limpar') {
            $this->flow->resetState($phone);
            try {
                $this->zapiClient->sendText($phone, "🗑️ Sessão resetada! Digite *oi* para começar.");
            } catch (\Throwable) {
            }
            return true;
        }

        // Contexto de checkout
        if (!empty($state['checkout_step'])) {
            // Repassa a bola para o CheckoutFlow tratar a digitação (e-mail, nome, endereço...)
            // O CheckoutFlow devolve true ou false, e a gente repassa essa resposta.
            return $this->checkoutFlow->handleCheckoutTextInput(
                $phone,
                $messageText, // O texto original (importante para e-mails e nomes próprios)
                $normalized,  // O texto em minúsculas (para comandos como 'cancelar')
                $state['checkout_step']
            );
        }
        // Keywords conhecidas
        return match ($normalized) {
            'carrinho' => $this->handleViewCart($phone),
            'finalizar', 'pagar', 'checkout' => $this->handleFinalize($phone),
            'lojas', 'ver lojas', 'mostrar lojas' => $this->storeHandle->sendStoresPage($phone, 0),
            'oi', 'ola', 'oie', 'menu', 'inicio', 'start' => $this->greetingFlow->sendWelcomePrompt($phone),
            default => $this->handleGenericSearch($phone, $messageText, $normalized)
        };
    }

    private function handleViewCart(string $phone): bool
    {
        try {
            $this->zapiClient->sendText($phone, 'Seu carrinho ainda está vazio.');
        } catch (\Throwable $e) {
            Log::warning('handleViewCart failed', ['error' => $e->getMessage()]);
        }
        return true;
    }

    private function handleFinalize(string $phone): bool
    {
        try {
            $this->zapiClient->sendText($phone, 'Vamos finalizar seu pedido!');
        } catch (\Throwable $e) {
            Log::warning('handleFinalize failed', ['error' => $e->getMessage()]);
        }
        return true;
    }

    private function handleGenericSearch(string $phone, string $messageText, string $normalized): bool
    {
        // Tenta buscar lojas pelo texto
        try {
            $sent = $this->storeHandle->sendStoreSearchResults($phone, $messageText);
            if ($sent) {
                return true;
            }
        } catch (\Throwable $e) {
            Log::warning('handleGenericSearch failed', ['error' => $e->getMessage()]);
        }

        // Fallback: boas vindas
        return $this->greetingFlow->sendWelcomePrompt($phone);
    }
}
