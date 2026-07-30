<?php

namespace App\Events\Orders;

use App\Models\Order;
use App\Services\Orders\OrderService;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento único do painel do lojista: pedido novo (pagamento aprovado), mudança de status e
 * aceite. Um evento só, com `reason` no payload, em vez de um por transição — o front trata
 * todos do mesmo jeito (atualiza/insere o card) e cada evento novo exigiria mais uma
 * subscrição do lado do cliente.
 *
 * `ShouldBroadcast` (enfileirado) e não `ShouldBroadcastNow`: assim uma falha do Reverb NÃO
 * derruba `POST /tenant/orders/{order}/accept` — o lojista continua operando e o alerta
 * chega pelo polling de fallback.
 */
class OrderChanged implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public Order $order,
        /** created|paid|status_changed */
        public string $reason,
    ) {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('orders.company.'.$this->order->company_id)];
    }

    public function broadcastAs(): string
    {
        return 'order.changed';
    }

    /**
     * Fila dedicada porque na `default` moram os jobs de WhatsApp/FlowBridge
     * (FLOWBRIDGE_TIMEOUT=15). Com um worker só, um lote de notificações lentas atrasaria o
     * alerta em ~1min e anularia o ganho do WebSocket. O worker consome
     * `--queue=broadcasts,payments,default`, nessa ordem de prioridade.
     */
    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }

    public function broadcastWith(): array
    {
        return [
            'reason' => $this->reason,
            'order' => app(OrderService::class)->presentForList($this->order),
            'payment_status' => $this->order->payment_status,
            'store_id' => $this->order->store_id,
        ];
    }
}
