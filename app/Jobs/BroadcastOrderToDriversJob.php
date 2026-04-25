<?php

namespace App\Jobs; // Estamos na raiz de Jobs agora

use App\Services\Zapi\ZapiClient;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class BroadcastOrderToDriversJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public Order $order;

    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    public function handle(ZapiClient $zapi): void
    {
        $groupJid = config('services.zapi.drivers_group_jid');

        // 1. Extrai o JSON do payload
        $payload = is_string($this->order->raw_payload)
            ? json_decode($this->order->raw_payload, true)
            : $this->order->raw_payload;

        // 2. Busca o endereço lá de dentro
        $address = $payload['customer']['address'] ?? 'Endereço não informado';

        // 3. Pega a referência para ajudar o entregador (opcional)
        $reference = $payload['customer']['reference'] ?? '';
        if (!empty($reference)) {
            $address .= " (Ref: {$reference})";
        }

        $message = "🚚 *NOVA ENTREGA DISPONÍVEL*\n"
                 . "🆔 Pedido: #{$this->order->code}\n"
                 . "📍 Endereço: {$address}\n"
                 . "💰 Taxa: R$ " . number_format((float)$this->order->delivery_fee, 2, ',', '.') . "\n\n"
                 . "Clique abaixo para aceitar:";

        $buttons = [[
            'id' => "accept_order|{$this->order->id}",
            'label' => "🟢 ACEITAR ENTREGA"
        ]];

        $response = $zapi->sendButtonActions($groupJid, $message, $buttons);

        // Salva o messageId da Z-API para podermos editar a mensagem no grupo depois
        if (isset($response['messageId'])) {
            $this->order->updateQuietly(['group_message_id' => $response['messageId']]);
        }
    }
}