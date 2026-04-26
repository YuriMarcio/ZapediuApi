<?php

namespace App\Jobs;

use App\Services\Zapi\ZapiClient;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BroadcastOrderToDriversJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public Order $order;

    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    public function handle(ZapiClient $zapi): void
    {
        $groupJid = config('services.zapi.drivers_group_jid');

        $payload = is_string($this->order->raw_payload)
            ? json_decode($this->order->raw_payload, true)
            : $this->order->raw_payload;

        $store = $this->order->store;
        $storeName = trim($store->name ?? 'Loja Parceira');
        $storeAddress = trim($store->full_address ?? 'Endereço da loja não informado');
        if ($storeAddress === '') {
            $storeAddress = 'Endereço da loja não informado';
        }

        $customerAddress = trim($payload['customer']['address'] ?? '');
        if ($customerAddress === '') {
            $customerAddress = 'Endereço não informado';
        }
        $reference = trim($payload['customer']['reference'] ?? '');

        // Distância
        $latLoja = $store->latitude ?? null;
        $lonLoja = $store->longitude ?? null;
        $latCliente = $payload['customer']['latitude'] ?? null;
        $lonCliente = $payload['customer']['longitude'] ?? null;

        $kmTexto = '';
        $tempoEntrega = '';
        if ($latLoja && $lonLoja && $latCliente && $lonCliente) {
            $distancia = $this->calculateStreetDistance($latLoja, $lonLoja, $latCliente, $lonCliente);
            if ($distancia) {
                $kmTexto = $distancia . ' km';
            }
        } else {
            // Fallback: calcular distância via endereço (Google Distance Matrix)
            $distanciaArr = self::calculateDistance($storeAddress, $customerAddress);
            if ($distanciaArr && isset($distanciaArr['text'])) {
                $kmTexto = $distanciaArr['text'];
                if (isset($distanciaArr['duration'])) {
                    // Personaliza o texto do tempo
                    $duration = $distanciaArr['duration']; // Ex: "18 min" ou "1 h 5 min"
                    $horas = 0;
                    $minutos = 0;
                    if (preg_match('/(\d+)\s*h/', $duration, $hMatch)) {
                        $horas = (int)$hMatch[1];
                    }
                    if (preg_match('/(\d+)\s*min/', $duration, $mMatch)) {
                        $minutos = (int)$mMatch[1];
                    }
                    if ($horas > 0) {
                        $tempoEntrega = "Entregar até {$horas}h{$minutos}min";
                    } elseif ($minutos > 0) {
                        $tempoEntrega = "Entregar até {$minutos}min";
                    } else {
                        $tempoEntrega = "Entregar em breve";
                    }
                }
            }
        }
        $itemsCount = count($payload['cart']['items'] ?? []);
        $status = $this->order->payment_status === 'paid' ? 'PAGO' : 'PAGAR NA ENTREGA';

        // Montagem da mensagem
        $lines = [];
        $lines[] = '📦 *NOVA CORRIDA DISPONÍVEL!* 🛵💨';
        $lines[] = '';
        $lines[] = "🏢 *Loja:* {$storeName}";
        $lines[] = "📍 *Retirada:* {$storeAddress}";
        $lines[] = '';
        $destino = "🏁 *Destino:* {$customerAddress}";
        if ($reference) {
            $destino .= " ({$reference})";
        }
        $lines[] = $destino;
        if ($kmTexto !== '') {
            $lines[] = "📏 *Distância Rota:* {$kmTexto}";
        }
        if ($tempoEntrega !== '') {
            $lines[] = "⏱️ {$tempoEntrega}";
        }
        $lines[] = "💰 *Taxa:* *R$ " . number_format((float)$this->order->delivery_fee, 2, ',', '.') . '*';
        $lines[] = '';
        $lines[] = "💳 *Pagamento:*({$status})";
        $lines[] = "📦 *Volume:* {$itemsCount} itens";
        $lines[] = '';
        $lines[] = 'Clique abaixo para aceitar:';

        $message = implode("\n", $lines);

        $buttons = [[
            'id' => "accept_order|{$this->order->id}",
            'label' => "🟢 ACEITAR ENTREGA"
        ]];

        $response = $zapi->sendButtonActions($groupJid, $message, $buttons);

        $msgId = $response['messageId'] ?? $response['id'] ?? null;
        if ($msgId) {
            $this->order->updateQuietly(['group_message_id' => $msgId]);
        }
    }

    /**
     * Calcula distância usando Google Distance Matrix API se não houver lat/lon
     */
    public static function calculateDistance($origin, $destination): ?array
    {
        $key = config('services.google.maps_key');
        if (!$key) {
            return null;
        }

        try {
            $response = \Illuminate\Support\Facades\Http::get('https://maps.googleapis.com/maps/api/distancematrix/json', [
                'origins' => $origin,
                'destinations' => $destination,
                'key' => $key,
                'language' => 'pt-BR',
                'mode' => 'driving', // Garante que o cálculo é via trajeto de carro/moto
                'units' => 'metric'
            ]);

            if ($response->successful() && $response->json('status') === 'OK') {
                $element = $response->json('rows.0.elements.0');

                if (($element['status'] ?? '') === 'OK') {
                    return [
                        'text'  => $element['distance']['text'],  // "5,2 km"
                        'value' => $element['distance']['value'], // 5200 (metros)
                        'duration' => $element['duration']['text'] // "12 min" (Bônus!)
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::error('Erro Distance Matrix: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * OSRM - Cálculo de rota de rua (100% Grátis)
     */
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
