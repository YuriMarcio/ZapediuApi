<?php

namespace App\Services\Delivery;

use App\Models\Store;

class DeliveryPricingService
{
    private const DEFAULT_FEE_PER_KM = 2.00;

    private const DEFAULT_FEE_MIN = 5.00;

    public function distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLon / 2) * sin($dLon / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    /**
     * Sem raio configurado ou sem coordenadas da loja: não bloqueia (loja ainda não configurou a área).
     */
    public function isWithinDeliveryArea(Store $store, float $customerLat, float $customerLng): bool
    {
        $radius = $store->delivery_radius_km !== null ? (float) $store->delivery_radius_km : null;

        if ($radius === null || $store->latitude === null || $store->longitude === null) {
            return true;
        }

        return $this->distanceKm((float) $store->latitude, (float) $store->longitude, $customerLat, $customerLng) <= $radius;
    }

    public function calculateFee(Store $store, ?float $customerLat, ?float $customerLng): float
    {
        $feeMin = $store->delivery_fee_min !== null ? (float) $store->delivery_fee_min : self::DEFAULT_FEE_MIN;

        if ($customerLat === null || $customerLng === null || $store->latitude === null || $store->longitude === null) {
            return round($feeMin, 2);
        }

        $feePerKm = $store->delivery_fee_per_km !== null ? (float) $store->delivery_fee_per_km : self::DEFAULT_FEE_PER_KM;

        $distanceKm = $this->distanceKm((float) $store->latitude, (float) $store->longitude, $customerLat, $customerLng);

        return round(max($feeMin, $distanceKm * $feePerKm), 2);
    }
}
