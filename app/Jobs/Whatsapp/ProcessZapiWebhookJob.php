<?php

namespace App\Jobs\Whatsapp;

use App\Actions\Webhooks\ProcessIncomingWebhookAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use App\Services\Whatsapp\AcceptDeliveryHandler;
use App\Services\Zapi\ZapiClient;
use Illuminate\Support\Facades\Log;

class ProcessZapiWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;
    public int $backoff = 10;

    public function __construct(
        private readonly ?int $companyId,
        private readonly array $payload,
    ) {
    }

    public function handle(ProcessIncomingWebhookAction $action, ZapiClient $zapi): void
    {
        // Pega o número real do motoboy
        $buttonId = $this->payload['buttonReply']['id'] ?? null;
        $driverPhone = $this->payload['participantPhone']
                    ?? $this->payload['buttonReply']['participantPhone']
                    ?? $this->payload['senderPhone']
                    ?? null;

        if ($driverPhone) {

            // A. Clicou em "ACEITAR ENTREGA" lá no grupo
            if ($buttonId && str_starts_with($buttonId, 'accept_order|')) {
                $handler = new AcceptDeliveryHandler();
                $handler->handle($driverPhone, $buttonId, $zapi, $driverName);
                return;
            }

            // B. Clicou em "FINALIZAR CORRIDA" no privado
            if ($buttonId && str_starts_with($buttonId, 'finish_order|')) {
                $orderId = explode('|', $buttonId)[1];

                // Salva no Redis que este motoboy está na tela de digitar código (Expira em 2 horas)
                \Illuminate\Support\Facades\Redis::set("waiting_code:{$driverPhone}", $orderId, 'EX', 7200);

                $zapi->sendText($driverPhone, "🔑 *Informe o código do cliente!*\n\nPeça ao cliente os últimos 4 caracteres/números do pedido e *digite aqui* para finalizar a entrega:");
                return;
            }

            // C. Digitou um TEXTO e o sistema está esperando o código dele
            $waitingOrderId = \Illuminate\Support\Facades\Redis::get("waiting_code:{$driverPhone}");
            $messageText = is_string($this->payload['text'] ?? null) ? $this->payload['text'] : ($this->payload['text']['text'] ?? null);

            // Se ele tem um pedido pendente e mandou um texto normal (não um botão)
            if ($waitingOrderId && $messageText && !$buttonId) {
                $handler = new \App\Services\Whatsapp\FinishDeliveryHandler();
                $handler->handle($driverPhone, $waitingOrderId, $messageText, $zapi);
                return;
            }
        }

        if (!$driverPhone && isset($this->payload['participant'])) {
            $driverPhone = explode('@', $this->payload['participant'])[0];
        }
        $driverPhone = $driverPhone ?: ($this->payload['phone'] ?? null);

        // 👉 PESCA O NOME DO MOTOBOY!
        $driverName = $this->payload['senderName'] ?? 'Um entregador';

        // Pega o botão clicado
        $buttonId = $this->payload['buttonId']
                 ?? $this->payload['text']['buttonId']
                 ?? $this->payload['listId']
                 ?? null;

        if (!$buttonId && isset($this->payload['text']['text']) && str_starts_with($this->payload['text']['text'], 'accept_order|')) {
            $buttonId = $this->payload['text']['text'];
        }

        // 🟢 BARREIRA DO MOTOBOY
        if ($driverPhone && $buttonId && str_starts_with($buttonId, 'accept_order|')) {

            $handler = new AcceptDeliveryHandler();
            // 👉 Passamos o nome dele aqui no final!
            $handler->handle($driverPhone, $buttonId, $zapi, $driverName);

            return;
        }

        $action->execute($this->payload, $this->companyId);
    }
}
