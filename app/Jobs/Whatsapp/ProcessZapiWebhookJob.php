<?php

namespace App\Jobs\Whatsapp;

use App\Actions\Webhooks\ProcessIncomingWebhookAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use App\Services\Whatsapp\AcceptDeliveryHandler;
use App\Services\Whatsapp\FinishDeliveryHandler;
use App\Services\Whatsapp\WhatsAppClientInterface;
use App\Services\Whatsapp\CourierConfirmationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class ProcessZapiWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;
    public int $backoff = 10;

    public function __construct(
        private readonly ?int $companyId,
        private readonly array $payload,
        private readonly ?string $whatsappInstanceId = null,
    ) {
    }

    public function handle(ProcessIncomingWebhookAction $action, WhatsAppClientInterface $zapi, CourierConfirmationService $confirmation, TenantContext $tenant): void
    {
        // Seta a instância ANTES de qualquer resolução de company — uma resposta (ex.: saudação
        // inicial) precisa sair pelo mesmo número que recebeu a mensagem, mesmo sem loja vinculada.
        $tenant->setWhatsappInstanceId($this->whatsappInstanceId);
        // Telefone de quem enviou (FlowBridge normaliza pra "phone"; os demais campos
        // ficam como fallback defensivo caso um payload não-normalizado chegue aqui).
        $driverPhone = $this->payload['phone']
                    ?? $this->payload['participantPhone']
                    ?? $this->payload['buttonReply']['participantPhone']
                    ?? $this->payload['senderPhone']
                    ?? null;

        if (!$driverPhone && isset($this->payload['participant'])) {
            $driverPhone = explode('@', $this->payload['participant'])[0];
        }

        $driverName = $this->payload['senderName'] ?? 'Um entregador';

        // Botão clicado (FlowBridge normaliza tanto respostas de botão quanto de lista
        // pra "buttonReply.id"; os demais campos ficam como fallback defensivo).
        $buttonId = $this->payload['buttonReply']['id']
                 ?? $this->payload['buttonId']
                 ?? $this->payload['text']['buttonId']
                 ?? $this->payload['listId']
                 ?? null;

        if (!$buttonId && isset($this->payload['text']['text']) && str_starts_with($this->payload['text']['text'], 'accept_order|')) {
            $buttonId = $this->payload['text']['text'];
        }

        if ($driverPhone && $buttonId && str_starts_with($buttonId, 'courier_confirm|')) {
            $confirmation->confirm($driverPhone, (int) explode('|', $buttonId)[1], $zapi);
            return;
        }

        if ($driverPhone && $buttonId && str_starts_with($buttonId, 'courier_group|')) {
            $confirmation->sendGroupLink($driverPhone, (int) explode('|', $buttonId)[1], $zapi);
            return;
        }

        // A. Clicou em "ACEITAR ENTREGA" lá no grupo
        if ($driverPhone && $buttonId && str_starts_with($buttonId, 'accept_order|')) {
            $handler = new AcceptDeliveryHandler();
            $handler->handle($driverPhone, $buttonId, $zapi, $driverName);
            return;
        }

        if ($driverPhone) {
            // B. Clicou em "FINALIZAR CORRIDA" no privado
            if ($buttonId && str_starts_with($buttonId, 'finish_order|')) {
                $orderId = explode('|', $buttonId)[1];

                // Salva no Redis que este motoboy está na tela de digitar código (Expira em 2 horas)
                Redis::set("waiting_code:{$driverPhone}", $orderId, 'EX', 7200);

                $zapi->sendText($driverPhone, "🔑 *Informe o código do cliente!*\n\nPeça ao cliente os últimos 4 caracteres/números do pedido e *digite aqui* para finalizar a entrega:");
                return;
            }

            // C. Digitou um TEXTO e o sistema está esperando o código dele
            $waitingOrderId = Redis::get("waiting_code:{$driverPhone}");
            $messageText = is_string($this->payload['text'] ?? null) ? $this->payload['text'] : ($this->payload['text']['text'] ?? null);

            // Se ele tem um pedido pendente e mandou um texto normal (não um botão)
            if ($waitingOrderId && $messageText && !$buttonId) {
                $handler = new FinishDeliveryHandler();
                $handler->handle($driverPhone, $waitingOrderId, $messageText, $zapi);
                return;
            }
        }

        $action->execute($this->payload, $this->companyId);
    }
}
