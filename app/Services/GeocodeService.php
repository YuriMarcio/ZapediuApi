<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeocodeService
{
    public static function getCoordinates(string $address): ?array
    {
        $key = config('services.google.maps_key');

        if (!$key) {
            Log::error("Google Maps API Key não configurada no config/services.php");
            return null;
        }

        try {
            $response = Http::get("https://maps.googleapis.com/maps/api/geocode/json", [
                'address'  => $address,
                'key'      => $key,
                'region'   => 'br',
                'language' => 'pt-BR'
            ]);

            if ($response->successful() && $response->json('status') === 'OK') {
                $location = $response->json('results.0.geometry.location');
                
                return [
                    'latitude'  => $location['lat'],
                    'longitude' => $location['lng'],
                    // Bônus: O Google devolve o endereço "oficial" formatado
                    'formatted' => $response->json('results.0.formatted_address') 
                ];
            }

            Log::warning("Google Geocode retornou status: " . $response->json('status'), ['address' => $address]);
            return null;

        } catch (\Throwable $e) {
            Log::error("Erro na comunicação com Google Maps: " . $e->getMessage());
            return null;
        }
    }
}