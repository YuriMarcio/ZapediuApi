<?php

namespace App\Services\Whatsapp;

use App\Models\Order;
use App\Services\Zapi\ZapiClient;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class AcceptDeliveryHandler
{
    public function handle(string $driverPhone, string $buttonId, ZapiClient $zapi, string $driverName = 'Um entregador'): void
    {
        Log::info("🟢 Motoboy {$driverName} ({$driverPhone}) tentou aceitar: {$buttonId}");

        if (str_starts_with($buttonId, 'accept_order|')) {
            $parts = explode('|', $buttonId);
            $orderId = (int) $parts[1];

            $this->processOrderAcceptance($driverPhone, $orderId, $zapi, $driverName);
        }
    }

    private function processOrderAcceptance(string $driverPhone, int $orderId, ZapiClient $zapi, string $driverName): void
    {
        $lockKey = "lock:order:{$orderId}:{$driverPhone}";

        if (!Redis::set($lockKey, 'locked', 'EX', 30, 'NX')) {
            return;
        }

        $driver = \App\Models\Courier::where('phone', $driverPhone)->first();

        Log::info("Driver lookup for phone {$driverPhone}: " . ($driver ? "Found ID {$driver->id}" : "Not found"));

        $driverId = $driver && $driver->is_active ? $driver->id : null;

        try {
            // 2. A MÁGICA DA CONCORRÊNCIA (A corrida maluca)
            $affectedRows = DB::table('orders')
                ->where('id', $orderId)
                ->where('status', 'preparToDelivery') // Busca pedido que ESTÁ esperando
                ->whereNull('courier_id')            // Garante que NINGUÉM pegou ainda
                ->update([
                    'status'      => 'delivering',    // Muda pra "A caminho"
                    'courier_id' => $driverId,       // O motoboy carimba o nome dele na entrega!
                    'updated_at'  => now(),
                ]);

            if ($affectedRows === 0) {
                $zapi->sendText($driverPhone, "❌ Tarde demais! Essa corrida já foi assumida por outro entregador.");
                return;
            }
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() == 23000) {
                // Mensagem amigável no grupo
                $order = Order::find($orderId);
                $groupJid = config('services.zapi.drivers_group_jid');
                $msg = '❌ Não foi possível aceitar o pedido. Ele ainda está disponível para outro entregador.';
                if ($groupJid) {
                    $zapi->sendText($groupJid, $msg);
                } else {
                    $zapi->sendText($driverPhone, $msg);
                }
                Log::warning('Erro de integridade ao aceitar pedido: ' . $e->getMessage());
                return;
            }
            throw $e;
        }

        $order = Order::find($orderId);
        $groupJid = config('services.zapi.drivers_group_jid');

        $payload = is_string($order->raw_payload)
            ? json_decode($order->raw_payload, true)
            : $order->raw_payload;

        $store = $order->store;
        $storeName = $store->name ?? 'Loja Parceira';

        // 👉 CORREÇÃO: Usando o seu accessor full_address!
        $storeAddress = $store->full_address ?? 'Endereço da loja não informado';

        $customerAddress = $payload['customer']['address'] ?? 'Endereço não informado';
        $reference = $payload['customer']['reference'] ?? '';

        $customerName = $payload['customer']['name'] ?? 'Cliente';
        $customerAddress = $payload['customer']['address'] ?? 'Endereço não informado';
        $reference = $payload['customer']['reference'] ?? '';

        if (!empty($reference)) {
            $customerAddress .= " (Ref: {$reference})";
        }

        // Calcula OSRM
        $latLoja = $store->latitude ?? null;
        $lonLoja = $store->longitude ?? null;
        $latCliente = $payload['customer']['latitude'] ?? null;
        $lonCliente = $payload['customer']['longitude'] ?? null;

        $kmTexto = "---";
        if ($latLoja && $lonLoja && $latCliente && $lonCliente) {
            $distancia = $this->calculateStreetDistance($latLoja, $lonLoja, $latCliente, $lonCliente);
            if ($distancia) {
                $kmTexto = "{$distancia} km";
            }
        }

        // Montagem dos Links GPS (Tenta usar Coordenadas primeiro, se não tiver, usa endereço em texto)
        $storeLocation = ($latLoja && $lonLoja) ? "{$latLoja},{$lonLoja}" : urlencode($storeAddress);
        $customerLocation = ($latCliente && $lonCliente) ? "{$latCliente},{$lonCliente}" : urlencode($customerAddress);

        $googleMapsLink = "https://www.google.com/maps/dir/?api=1&destination={$storeLocation}&destination={$customerLocation}";;

        $itemsList = "";
        if (isset($payload['cart']['items']) && is_array($payload['cart']['items'])) {
            foreach ($payload['cart']['items'] as $item) {
                $qtd = $item['quantity'] ?? 1;
                $nomeItem = $item['product_name'] ?? 'Item';
                $itemsList .= "➡️ {$qtd}x {$nomeItem}\n";
            }
        } else {
            $itemsList = "➡️ Itens não detalhados\n";
        }

        $paymentMethod = strtoupper($order->payment_method ?? 'NÃO INFORMADO');
        $paymentStatus = strtoupper($order->payment_status ?? 'PENDENTE');
        $feeFormatted = number_format((float)$order->delivery_fee, 2, ',', '.');
        $totalFormatted = number_format((float)$order->total, 2, ',', '.');

        $privateMsg = "✅ *ENTREGA CONFIRMADA!*\n"
                    . "Aqui estão as informações para realizar a entrega ⬇️🤩\n\n"
                    . "🏢 *Loja:* {$storeName}\n"
                    . "📍 *Retirada:*\n{$storeAddress}\n\n"
                    . "👤 *Cliente:* {$customerName}\n"
                    . "🆔 *Pedido:* #{$order->code}\n"
                    . "📍 *Entrega:*\n{$customerAddress}\n"
                    . "📏 *Distância Rota:* {$kmTexto}\n\n"
                    . "🗺️ *Rotas Automáticas:*\n"
                    . "📍 *Google Maps (Rota Completa):*\n{$googleMapsLink}\n\n"
                    . "🛍️ *Itens do Pedido:*\n{$itemsList}\n"
                    . "💳 *Pagamento:* {$paymentMethod} - {$paymentStatus}\n"
                    . "💰 *Taxa:* R$ {$feeFormatted}\n"
                    . "💵 *Total:* R$ {$totalFormatted}\n\n"
                    . "Bora fazer dinheiro! 🏍️💨";

        $buttons = [[
            'id' => "finish_order|{$order->id}",
            'label' => "🏁 FINALIZAR CORRIDA"
        ]];

        $zapi->sendButtonActions($driverPhone, $privateMsg, $buttons);

        $groupJid = config('services.zapi.drivers_group_jid');
        if ($groupJid) {
            $editMsg = "✅ O pedido *#{$order->code}* foi assumido por *{$driverName}*!";
            try {
                $zapi->sendText($groupJid, $editMsg);
            } catch (\Exception $e) {
                Log::error("Erro ao avisar no grupo: " . $e->getMessage());
            }
        }
    }

    private function calculateStreetDistance($latLoja, $lonLoja, $latCliente, $lonCliente): ?string
    {
        $url = "http://router.project-osrm.org/route/v1/driving/{$lonLoja},{$latLoja};{$lonCliente},{$latCliente}?overview=false";
        try {
            $response = Http::timeout(3)->get($url);
            if ($response->successful()) {
                $data = $response->json();
                if (isset($data['routes'][0]['distance'])) {
                    return number_format($data['routes'][0]['distance'] / 1000, 1, ',', '.');
                }
            }
        } catch (\Exception $e) {
            Log::error("Erro OSRM: " . $e->getMessage());
        }
        return null;
    }
}
