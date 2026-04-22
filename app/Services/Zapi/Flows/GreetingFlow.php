<?php

namespace App\Services\Zapi\Flows;

use App\Services\Zapi\ZapiClient;
use App\Services\Zapi\Flows\FlowManager;
use App\Services\Zapi\Support\StoreSearch;
use App\Services\Zapi\Handlers\StoreHandle; // FIX: use adicionado (estava faltando)
use Illuminate\Support\Facades\Log;

class GreetingFlow
{
    public function __construct(
        private readonly ZapiClient $zapiClient,
        private readonly FlowManager $flow,
        private readonly StoreSearch $search,
        private readonly StoreHandle $storeHandle
        // GeminiClient removido do construtor até estar implementado.
        // Quando pronto, adicione: private readonly GeminiClient $gemini,
        // e reabilite o bloco de intent abaixo.
    ) {
    }

    public function handleFirstMessage(string $phone, string $messageText, string $normalizedText): bool
    {
        $state = $this->flow->getState($phone);

        if (($state['welcomed'] ?? false) === true) {
            return false;
        }

        $state['welcomed'] = true;
        $this->flow->save($phone, $state);

        $cumprimentos = ['oi', 'ola', 'oie', 'menu', 'inicio', 'start'];

        // FIX: bloco Gemini desabilitado — $this->gemini causava Error fatal pois
        // GeminiClient estava comentado no construtor mas ainda era chamado aqui.
        // Quando GeminiClient estiver implementado, remova o early-return abaixo
        // e reabilite o bloco comentado.
        if (mb_strlen($messageText) > 14) {
            // --- Reabilite quando GeminiClient estiver pronto ---
            // $intent = $this->gemini->analyzeIntent($messageText);
            // if ($intent && isset($intent['tipo'], $intent['valor'])) {
            //     return $this->routeByIntent($phone, $intent);
            // }
            // ----------------------------------------------------

            // Por enquanto, tenta busca direta por texto longo também
            $storeIds = $this->search->byQuery($messageText);
            if (!empty($storeIds)) {
                $state['store_results'] = $storeIds;
                $this->flow->save($phone, $state);
                return $this->storeHandle->renderPage($phone, 0);
            }

            return $this->sendWelcomePrompt($phone);
        }

        Log::info("Texto curto recebido: '{$messageText}' - tentando correspondência direta.");

        // Fluxo padrão para cumprimentos ou textos curtos
        if (!in_array($normalizedText, $cumprimentos, true)) {
            $storeIds = $this->search->byQuery($messageText);
            if (!empty($storeIds)) {
                $state['store_results'] = $storeIds;
                $this->flow->save($phone, $state);
                return $this->storeHandle->renderPage($phone, 0);
            }
        }

        return $this->sendWelcomePrompt($phone);
    }

    // FIX: método mantido mas protegido — só será chamado quando Gemini estiver ativo.
    // Métodos byProduct() e byCategory() em StoreSearch precisam existir antes de reabilitar.
    private function routeByIntent(string $phone, array $intent): bool
    {
        $storeIds = match($intent['tipo']) {
            'loja'      => $this->search->byQuery($intent['valor']),
            'produto'   => method_exists($this->search, 'byProduct')
                            ? $this->search->byProduct($intent['valor'])
                            : $this->search->byQuery($intent['valor']),
            'categoria' => method_exists($this->search, 'byCategory')
                            ? $this->search->byCategory($intent['valor'])
                            : $this->search->byQuery($intent['valor']),
            default     => []
        };

        if (!empty($storeIds)) {
            $state = $this->flow->getState($phone);
            $state['store_results'] = $storeIds;
            $this->flow->save($phone, $state);
            return $this->storeHandle->renderPage($phone, 0);
        }

        return $this->sendWelcomePrompt($phone);
    }

    public function sendWelcomePrompt(string $phone): bool
    {
        $message = "Olá! 👋 Bem-vindo ao Zapediu!\n\nEstou aqui para matar a sua fome em poucos segundos. 🛵💨\n\nVocê pode simplesmente me dizer o que quer comer, por exemplo:\n🍔 \"Quero um hambúrguer\"\n🍕 \"Me mostre as pizzarias\"\n🏪 \"Cardápio do Pastel do Zeca\"\n\nOu, se preferir, escolha uma das opções abaixo:";
        $fallback = "Olá! 👋 Bem-vindo ao Zapediu! Use as opções abaixo ou digite o que procura:";

        try {
            $this->zapiClient->sendButtonActions($phone, $message, [
                ['id' => 'btn_ver_lojas',      'label' => '🏪 Ver Lojas'],
                ['id' => 'btn_ver_categorias', 'label' => '🍔 Ver Categorias'],
                ['id' => 'btn_como_funciona',  'label' => '❓ Como funciona'],
            ]);
            return true; // ← adicione isso
        } catch (\Throwable $e) {
            Log::warning('Erro botões welcome: ' . $e->getMessage());
            try {
                $this->zapiClient->sendText($phone, $fallback);
                return true;
            } catch (\Throwable) {
                return false;
            }
        }
    }
}
