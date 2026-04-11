<?php

namespace App\Services\Payments;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MercadoPagoClient
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createCheckoutPreference(array $payload): array
    {
        $response = $this->request()
            ->withHeaders(['X-Idempotency-Key' => (string) ($payload['external_reference'] ?? uniqid('mp_', true))])
            ->post('/checkout/preferences', $payload)
            ->throw();

        /** @var array<string, mixed> */
        return $response->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayment(string $paymentId): array
    {
        $response = $this->request()
            ->get('/v1/payments/'.$paymentId)
            ->throw();

        /** @var array<string, mixed> */
        return $response->json();
    }

    private function request(): PendingRequest
    {
        $accessToken = trim((string) config('services.mercado_pago.access_token', ''));

        if ($accessToken === '') {
            throw new RuntimeException('Mercado Pago access token is not configured.');
        }

        return Http::baseUrl((string) config('services.mercado_pago.base_url', 'https://api.mercadopago.com'))
            ->acceptJson()
            ->asJson()
            ->withToken($accessToken)
            ->timeout((int) config('services.mercado_pago.timeout', 20));
    }
}