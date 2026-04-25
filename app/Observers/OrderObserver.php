<?php

namespace App\Observers;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Jobs\BroadcastOrderToDriversJob;
use Illuminate\Support\Facades\Log;

class OrderObserver
{
    public function updated(Order $order): void
    {
        // Se a Model converter para Enum, pegamos o valor (value). Se não, usamos direto.
        $statusAtual = $order->status instanceof OrderStatus 
            ? $order->status->value 
            : $order->status;

        Log::info("🚨 OBSERVER CHAMADO! Pedido: {$order->id} | Status: {$statusAtual}");

        // Compara com o valor em texto do Enum ('preparToDelivery')
        if ($order->isDirty('status') && $statusAtual === OrderStatus::PreparToDelivery->value) {
            
            Log::info("✅ PASSOU NO IF! Mandando pra fila...");
            dispatch(new BroadcastOrderToDriversJob($order));
            
        } else {
            Log::warning("❌ NÃO PASSOU NO IF. isDirty: " . ($order->isDirty('status') ? 'sim' : 'nao'));
        }
    }
}