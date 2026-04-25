<?php

namespace App\Services\Whatsapp; // Morando na pasta certinha

use App\Models\Order;
use App\Services\Zapi\ZapiClient;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AcceptDeliveryHandler 
{
    /**
     * Ponto de entrada quando o motoboy clica no botão
     */
    public function handle(string $driverPhone, string $buttonId, ZapiClient $zapi): void
    {
        Log::info("🟢 Motoboy {$driverPhone} tentou aceitar a corrida: {$buttonId}");

        if (str_starts_with($buttonId, 'accept_order|')) {
            $parts = explode('|', $buttonId);
            $orderId = (int) $parts[1]; 
            
            $this->processOrderAcceptance($driverPhone, $orderId, $zapi);
        }
    }

    /**
     * Lógica blindada contra concorrência (Race Condition Lock)
     */
    private function processOrderAcceptance(string $driverPhone, int $orderId, ZapiClient $zapi): void
    {
        $lockKey = "lock:order:{$orderId}";

        // 1. A BARREIRA DO REDIS (A Mágica Atômica - dura 10 segs)
        if (!Redis::set($lockKey, 'locked', 'EX', 10, 'NX')) {
            $zapi->sendText($driverPhone, "❌ *Putz!* Outro colega foi milissegundos mais rápido e já pegou essa entrega.");
            return;
        }

        try {
            // 2. A BARREIRA DO SQL (Garantia final)
            $affectedRows = DB::table('orders')
                ->where('id', $orderId)
                ->where('status', 'preparToDelivery')
                ->update([
                    'status' => 'delivering',
                    // 'courier_phone' => $driverPhone, // No futuro, você salva o ID do motoboy aqui
                    'updated_at' => now(),
                ]);

            if ($affectedRows === 0) {
                $zapi->sendText($driverPhone, "❌ Tarde demais! Essa corrida já foi assumida por outro entregador.");
                return;
            }

            // 3. SUCESSO! Recupera os dados
            $order = Order::find($orderId);
            $payload = is_string($order->raw_payload) ? json_decode($order->raw_payload, true) : $order->raw_payload;
            $address = $payload['customer']['address'] ?? 'Endereço não informado';
            $reference = $payload['customer']['reference'] ?? '';
            
            if (!empty($reference)) {
                $address .= " (Ref: {$reference})";
            }

            // 4. Manda as coordenadas no PRIVADO do motoboy vencedor
            $privateMsg = "✅ *ENTREGA CONFIRMADA!*\n\n"
                        . "Você assumiu o pedido *#{$order->code}*.\n"
                        . "📍 *Endereço:* {$address}\n\n"
                        . "Bora fazer dinheiro! 🏍️💨";
            $zapi->sendText($driverPhone, $privateMsg);

            if ($order->group_message_id) {
                $groupJid = config('services.zapi.drivers_group_jid');
                $editMsg = "✅ O pedido *#{$order->code}* já foi assumido pelo motoboy!";
                
                try {
                    // Trocamos o updateMessage pelo sendText que já sabemos que funciona!
                    $zapi->sendText($groupJid, $editMsg); 
                } catch (\Exception $e) {
                    Log::error("Erro ao avisar no grupo: " . $e->getMessage());
                }
            }

        } finally {
            // 6. Libera a trava
            Redis::del($lockKey);
        }
    }
}