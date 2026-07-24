<?php

namespace App\Observers;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Jobs\BroadcastOrderToDriversJob;
use App\Services\Whatsapp\WhatsAppOrchestrator;
use Illuminate\Support\Facades\Log;

class OrderObserver
{
    public function updated(Order $order): void
    {
        // Se a Model converter para Enum, pegamos o valor (value). Se não, usamos direto.
        $statusAtual = $order->status instanceof OrderStatus
            ? $order->status->value
            : $order->status;

        if (! $order->isDirty('status')) {
            return;
        }

        Log::info("OrderObserver: status change", ['order_id' => $order->id, 'status' => $statusAtual]);

        if ($statusAtual === OrderStatus::PreparToDelivery->value) {
            dispatch(new BroadcastOrderToDriversJob($order));
        }

        $this->notifyCustomerOfStatus($order, (string) $statusAtual);
    }

    /**
     * §14 do documento — cada mudança de status dispara uma mensagem automática pro cliente,
     * sem ele precisar perguntar "cadê meu pedido?". O status "pago" (§14.1) é disparado
     * pelo webhook de pagamento; "entregue" (§14.4) pelo FinishDeliveryHandler.
     */
    private function notifyCustomerOfStatus(Order $order, string $status): void
    {
        $message = match ($status) {
            OrderStatus::Accepted->value,
            OrderStatus::Preparing->value => "👨‍🍳 Seu pedido foi aceito e já está sendo preparado!\n"
                .'⏳ Tempo estimado: 40-50 min',
            OrderStatus::Delivering->value => "🛵 Seu pedido saiu para entrega!\n"
                ."Chegando em ~20-30 min.\n\n"
                ."📌 *Código de confirmação: #{$order->code}*",
            default => null,
        };

        if ($message === null) {
            return;
        }

        $phone = $order->user?->phone ?: $order->user?->primaryPhone?->phone;

        if (! $phone) {
            return;
        }

        try {
            app(WhatsAppOrchestrator::class)->sendStatusNotificationNow(
                (int) ($order->company_id ?? 0),
                (string) $phone,
                $message,
                []
            );
        } catch (\Throwable $e) {
            Log::warning('OrderObserver: failed to notify customer of status change.', [
                'order_id' => $order->id,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
