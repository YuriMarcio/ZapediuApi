<?php

namespace App\Services\Whatsapp;

use App\Models\Order;
use App\Services\Zapi\ZapiClient;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

class FinishDeliveryHandler 
{
    public function handle(string $driverPhone, int $orderId, string $typedCode, ZapiClient $zapi): void
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
        } else {
            // ❌ CÓDIGO ERRADO
            $zapi->sendText($driverPhone, "❌ *Código Incorreto!*\n\nVocê digitou *{$typedCode}*. Confirme com o cliente os últimos 4 caracteres do número do pedido e digite novamente:");
        }
    }
}