<?php

namespace App\Services\Whatsapp;

use App\Models\Order;
use App\Services\Whatsapp\WhatsAppClientInterface;
use App\Services\Zapi\Flows\FlowManager;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

class FinishDeliveryHandler
{
    public function __construct(
        private readonly FlowManager $flow
    ) {
    }

    public function handle(string $driverPhone, int $orderId, string $typedCode, WhatsAppClientInterface $zapi): void
    {
        $order = Order::find($orderId);

        if (!$order) {
            $zapi->sendText($driverPhone, "❌ Pedido não encontrado.");
            Redis::del("waiting_code:{$driverPhone}");
            return;
        }

        // Pega os últimos 4 caracteres do código do pedido (Ex: ZMFD)
        $expectedCode = substr($order->code, -4);

        // Limpa espaços e deixa tudo maiúsculo para não ter erro de digitação
        $typedCode = strtoupper(trim($typedCode));

        if ($typedCode === strtoupper($expectedCode)) {
            // ✅ CÓDIGO CERTO! Finaliza a corrida!
            $order->status = 'delivered'; // ou 'completed', dependendo de como está o seu sistema
            $order->save();

            // Libera o motoboy do modo "esperando código"
            Redis::del("waiting_code:{$driverPhone}");

            $zapi->sendText($driverPhone, "✅ *CORRIDA FINALIZADA!*\n\nCódigo validado com sucesso. Excelente trabalho, parceiro! 🚀");
            
            Log::info("🏁 Motoboy finalizou a entrega do pedido {$orderId} com sucesso.");

            // Reset customer session and send thank you message
            $this->resetCustomerSession($order, $zapi);
        } else {
            // ❌ CÓDIGO ERRADO
            $zapi->sendText($driverPhone, "❌ *Código Incorreto!*\n\nVocê digitou *{$typedCode}*. Confirme com o cliente os últimos 4 caracteres do número do pedido e digite novamente:");
        }
    }

    /**
     * Reset customer session and send thank you message
     */
    private function resetCustomerSession(Order $order, WhatsAppClientInterface $zapi): void
    {
        try {
            // Get customer phone from order
            $customerPhone = $order->user?->phone;
            if (!$customerPhone && $order->user?->primaryPhone) {
                $customerPhone = $order->user->primaryPhone->phone;
            }

            if ($customerPhone) {
                // Reset customer session
                $this->flow->resetState($customerPhone);

                // §14.4 do documento — pedido entregue + convite de avaliação
                $storeName = $order->store?->name ?? 'a loja';
                $message = "🎉 *Pedido entregue!*\n";
                $message .= "Esperamos do fundo do coração que você tenha gostado. 💚\n\n";
                $message .= "Se puder, deixa um feedback rápido pra {$storeName} sobre o pedido — isso ajuda a loja a continuar melhorando:";

                $zapi->sendButtonActions($customerPhone, $message, [
                    ['id' => 'rate_order_'.$order->id, 'label' => '⭐ Avaliar Pedido'],
                ]);
                
                Log::info("Customer session reset and thank you sent for order {$order->code}", [
                    'customer_phone' => $customerPhone,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('Failed to reset customer session', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}