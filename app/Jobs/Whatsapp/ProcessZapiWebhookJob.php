<?php

namespace App\Jobs\Whatsapp;

use App\Actions\Webhooks\ProcessIncomingWebhookAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use App\Services\Whatsapp\AcceptDeliveryHandler;
use App\Services\Zapi\ZapiClient;
use Illuminate\Support\Facades\Log; // <-- Adicionado para o Log funcionar

class ProcessZapiWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;
    public int $backoff = 10;
    
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        private readonly ?int $companyId,
        private readonly array $payload,
    ) {
    }

    public function handle(ProcessIncomingWebhookAction $action, ZapiClient $zapi): void
    {
        // 🕵️‍♂️ O ESPIÃO MÁSTER: Grava tudo no exato milissegundo que chega na fila!
        Log::info("🔍 PAYLOAD Z-API COMPLETO: ", $this->payload);

        // 1. A Rede de Arrasto para achar o número REAL do motoboy
        $driverPhone = $this->payload['participantPhone'] 
                    ?? $this->payload['buttonReply']['participantPhone'] 
                    ?? $this->payload['senderPhone'] 
                    ?? null;

        // Versões que mandam 'participant' com @g.us ou @s.whatsapp.net no final
        if (!$driverPhone && isset($this->payload['participant'])) {
            $driverPhone = explode('@', $this->payload['participant'])[0];
        }

        // Se falhar tudo, cai de volta pro phone normal
        $driverPhone = $driverPhone ?: ($this->payload['phone'] ?? null);

        // 2. Qual foi o botão clicado?
        $buttonId = $this->payload['buttonId'] 
                 ?? $this->payload['text']['buttonId'] 
                 ?? $this->payload['listId'] 
                 ?? null;

        if (!$buttonId && isset($this->payload['text']['text']) && str_starts_with($this->payload['text']['text'], 'accept_order|')) {
            $buttonId = $this->payload['text']['text'];
        }

        // 3. 🟢 A NOSSA BARREIRA DO MOTOBOY
        if ($driverPhone && $buttonId && str_starts_with($buttonId, 'accept_order|')) {
            
            $handler = new AcceptDeliveryHandler();
            $handler->handle($driverPhone, $buttonId, $zapi);
            
            return; // Encerra o Job AQUI.
        }

        // 4. Se não for o botão, segue a vida normal
        $action->execute($this->payload, $this->companyId);
    }
}