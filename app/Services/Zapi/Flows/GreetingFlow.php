<?php

namespace App\Services\Zapi\Flows;

use App\Services\Zapi\ZapiClient;
use App\Services\Zapi\Flows\FlowManager;
use App\Services\Zapi\Flows\CheckoutFlow;
use App\Services\Zapi\Support\StoreSearch;
use App\Services\Zapi\Handlers\StoreHandle;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\App;

class GreetingFlow
{
    public function __construct(
        private readonly ZapiClient $zapiClient,
        private readonly FlowManager $flow,
        private readonly StoreSearch $search,
        private readonly StoreHandle $storeHandle,
        // private readonly GeminiClient $gemini, // Descomente quando implementar
    ) {
    }

    public function handleFirstMessage(string $phone, string $messageText, string $normalizedText): bool
    {
        $state = $this->flow->getState($phone);

        if ($state['welcomed'] ?? false) {
            return false;
        }

        // BLOQUEIO IMEDIATO: Marca como saudado ANTES de processar
        // Isso evita que um segundo webhook processado em paralelo dispare de novo
        $this->markAsWelcomed($phone, $state);

        $cumprimentos = ['oi', 'ola', 'oie', 'menu', 'inicio', 'start'];

        // Se for intenção de busca
        if (mb_strlen($normalizedText) > 3 && !in_array($normalizedText, $cumprimentos)) {
            $storeIds = $this->search->byQuery($messageText);
            if (!empty($storeIds)) {
                $state['store_results'] = $storeIds;
                $this->flow->save($phone, $state);
                return $this->storeHandle->renderPage($phone, 0);
            }
        }

        // Se não for busca, manda o welcome
        return $this->sendWelcomePrompt($phone);
    }

    private function markAsWelcomed(string $phone, array $state): void
    {
        $state['welcomed'] = true;
        $this->flow->save($phone, $state);
    }

    private function routeByIntent(string $phone, array $intent): bool
    {
        $storeIds = match($intent['tipo']) {
            'loja'      => $this->search->byQuery($intent['item']),
            'produto'   => method_exists($this->search, 'byProduct')
                            ? $this->search->byProduct($intent['item'])
                            : $this->search->byQuery($intent['item']),
            'categoria' => method_exists($this->search, 'byCategory')
                            ? $this->search->byCategory($intent['item'])
                            : $this->search->byQuery($intent['item']),
            default     => []
        };

        if (!empty($storeIds)) {
            $state = $this->flow->getState($phone);
            $state['store_results'] = $storeIds;
            $this->flow->save($phone, $state);
            return $this->storeHandle->renderPage($phone, 0);
        }

        return false;
    }

    public function sendWelcomePrompt(string $phone): bool
    {
        // Check for active orders first (re-entry protection)
        $checkoutFlow = App::make(CheckoutFlow::class);
        if ($checkoutFlow->checkActiveOrderRedirect($phone)) {
            return true; // Already redirected to order status
        }

        $message = "Olá! 👋 Bem-vindo ao Zapediu!\n\nEstou aqui para matar a sua fome em poucos segundos. 🛵💨\n\nO que você quer fazer hoje?";
        $fallback = "Olá! 👋 Bem-vindo ao Zapediu! Use as opções abaixo ou digite o que procura (ex: 'Quero Pizza'):";

        // Tenta enviar botões
        $sent = $this->zapiClient->sendButtonActions($phone, $message, [
            ['id' => 'btn_ver_lojas',      'label' => '🏪 Ver Lojas'],
            ['id' => 'btn_ver_categorias', 'label' => '🍔 Categorias'],
            ['id' => 'btn_como_funciona',  'label' => '❓ Ajuda'],
        ]);

        // Se retornou sucesso (true ou objeto), encerramos aqui.
        if ($sent) {
            return true;
        }

        // Só chega aqui se o envio de botões falhar silenciosamente ou retornar falso
        Log::warning("Botões falharam para $phone, enviando fallback de texto.");
        return $this->zapiClient->sendText($phone, $fallback);
    }
}
