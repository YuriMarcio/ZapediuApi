<?php

namespace App\Services\Zapi\Handlers;

use App\Services\Zapi\Flows\FlowManager;
use App\Services\Zapi\Flows\GreetingFlow;
use App\Services\Zapi\Flows\CheckoutFlow;
use App\Services\Zapi\Handlers\CategoriesHandle;
use App\Services\Zapi\Handlers\StoreHandle;
use App\Services\Zapi\ZapiClient;
use Illuminate\Support\Facades\Log;

class TextHandler
{
    public function __construct(
        private FlowManager $flow,
        private GreetingFlow $greetingFlow,
        private StoreHandle $storeHandle,
        private ZapiClient $zapiClient,
        private CheckoutFlow $checkoutFlow,
        private CategoriesHandle $categoriesHandle

    ) {
    }

    public function handle(string $phone, string $messageText): bool
    {
        // Normaliza o texto da mensagem (ex: minúsculas, sem acentos)
        $normalized = $this->flow->normalize($messageText);
        // Recupera o estado atual do usuário (por telefone)
        $state = $this->flow->getState($phone);

        // Comando global: se o usuário digitar "limpar"
        if ($normalized === 'limpar') {
            // Reseta o estado da conversa do usuário
            $this->flow->resetState($phone);
            try {
                // Envia mensagem avisando que a sessão foi resetada
                $this->zapiClient->sendText($phone, "🗑️ Sessão resetada! Digite *oi* para começar.");
            } catch (\Throwable) {
                // Ignora erros ao enviar mensagem
            }
            // Retorna true indicando que o comando foi tratado
            return true;
        }

        // Se o usuário está em um fluxo de checkout (ex: preenchendo dados)
        if (!empty($state['checkout_step'])) {
            // Delega o tratamento do texto para o CheckoutFlow
            // Passa o texto original, o texto normalizado e o passo atual do checkout
            return $this->checkoutFlow->handleCheckoutTextInput(
                $phone,
                $messageText, // Texto original (importante para e-mails e nomes próprios)
                $normalized,  // Texto em minúsculas (para comandos como 'cancelar')
                $state['checkout_step']
            );
        }

        // Trata palavras-chave conhecidas usando match
        return match ($normalized) {
            // Se o usuário digitar "carrinho"
            'carrinho' => $this->handleViewCart($phone),
            // Se digitar "finalizar", "pagar" ou "checkout"
            'finalizar', 'pagar', 'checkout' => $this->handleFinalize($phone),
            // Se digitar "lojas", "ver lojas" ou "mostrar lojas"
            'lojas', 'ver lojas', 'mostrar lojas' => $this->storeHandle->sendStoresPage($phone, 0),
            // Se digitar "oi", "ola", "oie", "menu", "inicio" ou "start"
            'oi', 'ola', 'oie', 'menu', 'inicio', 'start' => $this->greetingFlow->sendWelcomePrompt($phone),
            // Qualquer outro texto cai no tratamento genérico de busca
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
        Log::info('to por aqui');
        // Tenta buscar lojas pelo texto
        try {
            $sent = $this->storeHandle->sendStoreSearchResults($phone, $messageText);
            Log::info("handleGenericSearch: searched for '{$messageText}' and sent results: " . ($sent ? 'yes' : 'no'));
            if ($sent) {
                return true;
            }
        } catch (\Throwable $e) {
            return $this->categoriesHandle->returnToStores($phone);
        }

        // Fallback: boas vindas
        return $this->greetingFlow->sendWelcomePrompt($phone);
    }
}
