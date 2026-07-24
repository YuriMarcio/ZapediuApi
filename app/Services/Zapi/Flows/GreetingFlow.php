<?php

namespace App\Services\Zapi\Flows;

use App\Services\Whatsapp\WhatsAppClientInterface;
use App\Services\Zapi\Flows\FlowManager;
use App\Services\Zapi\Flows\CheckoutFlow;
use App\Services\Zapi\Support\StoreSearch;
use App\Services\Zapi\Handlers\StoreHandle;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\App;

class GreetingFlow
{
    public function __construct(
        private readonly WhatsAppClientInterface $zapiClient,
        private readonly FlowManager $flow,
        private readonly StoreSearch $search,
        private readonly StoreHandle $storeHandle,
    ) {
    }

    public function sendWelcomePrompt(string $phone): bool
    {
        // Check for active orders first (re-entry protection)
        $checkoutFlow = App::make(CheckoutFlow::class);
        if ($checkoutFlow->checkActiveOrderRedirect($phone)) {
            return true; // Already redirected to order status
        }

        $title = 'Bem-vindo ao Zapediu! 👋';
        $message = "Estou aqui para matar a sua fome em poucos segundos. 🛵💨\n\nO que você quer fazer hoje?";
        $fallback = "{$title}\n\nUse as opções abaixo ou digite o que procura (ex: 'Quero Pizza'):";

        // Tenta enviar botões
        $sent = $this->zapiClient->sendButtonActions($phone, $message, [
            ['id' => 'btn_ver_lojas',      'label' => '🏪 Ver Lojas'],
            ['id' => 'btn_ver_categorias', 'label' => '🍔 Categorias'],
            ['id' => 'btn_como_funciona',  'label' => '❓ Ajuda'],
        ], $title);

        // Se retornou sucesso (true ou objeto), encerramos aqui.
        if ($sent) {
            return true;
        }

        // Só chega aqui se o envio de botões falhar silenciosamente ou retornar falso
        Log::warning("Botões falharam para $phone, enviando fallback de texto.");
        return $this->zapiClient->sendText($phone, $fallback);
    }
}
