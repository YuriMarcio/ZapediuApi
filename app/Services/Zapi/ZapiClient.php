<?php

namespace App\Services\Zapi;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class ZapiClient
{
    public function sendText(string $phone, string $message): array
    {
        $path = $this->endpointPath('/send-text');

        $response = $this->http()
            ->post($path, [
                'phone' => $phone,
                'message' => $message,
            ])
            ->throw();

        return $this->decodeResponse($response);
    }

    public function sendCarousel(string $phone, string $message, array $carousel): array
    {
        $path = $this->endpointPath('/send-carousel');

        $response = $this->http()
            ->post($path, [
                'phone' => $phone,
                'message' => $message,
                'carousel' => $carousel,
            ])
            ->throw();

        return $this->decodeResponse($response);
    }

    public function sendButtonList(string $phone, string $message, array $buttons): array
    {
        $path = $this->endpointPath('/send-button-list');

        $response = $this->http()
            ->post($path, [
                'phone' => $phone,
                'message' => $message,
                'buttonList' => [
                    'buttons' => array_values(array_map(
                        fn (array $button): array => [
                            'id' => (string) ($button['id'] ?? ''),
                            'label' => (string) ($button['label'] ?? ''),
                        ],
                        $buttons
                    )),
                ],
            ])
            ->throw();

        return $this->decodeResponse($response);
    }

    public function sendButtonActions(string $phone, string $message, array $buttons, ?string $title = null, ?string $footer = null): array
    {
        $path = $this->endpointPath('/send-button-actions');

        $response = $this->http()
            ->post($path, array_filter([
                'phone' => $phone,
                'message' => $message,
                'title' => $title,
                'footer' => $footer,
                'buttonActions' => array_values(array_map(
                    fn (array $button): array => array_filter([
                        'id' => $button['id'] ?? null,
                        'type' => $button['type'] ?? 'REPLY',
                        'label' => $button['label'] ?? '',
                        'phone' => $button['phone'] ?? null,
                        'url' => $button['url'] ?? null,
                    ], fn (mixed $value): bool => $value !== null),
                    $buttons
                )),
            ], fn (mixed $value): bool => $value !== null));

        $response->throw();

        return $this->decodeResponse($response);
    }

    public function sendList(
        string $phone,
        string $message,
        string $buttonText,
        string $title,
        string $description,
        array $options
    ): array {
        $path = $this->endpointPath('/send-option-list');

        $response = $this->http()
            ->post($path, [
                'phone' => $phone,
                'message' => $message,
                'optionList' => [
                    'title' => $title,
                    'buttonLabel' => $buttonText,
                    'options' => array_values(array_map(
                        fn (array $option): array => array_filter([
                            'id' => $option['id'] ?? null,
                            'title' => $option['title'] ?? '',
                            'description' => $option['description'] ?? '',
                        ], fn (mixed $value): bool => $value !== null),
                        $options
                    )),
                ],
            ])
            ->throw();

        return $this->decodeResponse($response);
    }

    public function sendCatalog(string $phone, string $catalogPhone, array $options = []): array
    {
        $path = $this->endpointPath('/send-catalog');

        $response = $this->http()
            ->post($path, array_filter([
                'phone' => $phone,
                'catalogPhone' => $catalogPhone,
                'translation' => $options['translation'] ?? null,
                'message' => $options['message'] ?? null,
                'title' => $options['title'] ?? null,
                'catalogDescription' => $options['catalogDescription'] ?? null,
            ], fn (mixed $value) => $value !== null && $value !== ''))
            ->throw();

        return $this->decodeResponse($response);
    }

    public function sendProduct(string $phone, string $catalogPhone, string $productId): array
    {
        $path = $this->endpointPath('/send-product');

        $response = $this->http()
            ->post($path, [
                'phone' => $phone,
                'catalogPhone' => $catalogPhone,
                'productId' => $productId,
            ])
            ->throw();

        return $this->decodeResponse($response);
    }

    private function decodeResponse(Response $response): array
    {
        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())
            ->acceptJson()
            ->asJson()
            ->withHeaders([
                'Client-Token' => (string) config('services.zapi.client_token'),
            ]);
    }

    private function baseUrl(): string
    {
        $base = rtrim((string) config('services.zapi.base_url'), '/');
        $instanceId = trim((string) config('services.zapi.instance_id'));
        $instanceToken = trim((string) config('services.zapi.instance_token'));

        // Compatibilidade com env antigo que já vinha com /send-xxx no final.
        $base = preg_replace('#/send-[a-z\-]+$#i', '', $base) ?? $base;

        if (str_contains($base, '/instances/') && str_contains($base, '/token/')) {
            return $base;
        }

        return $base.'/instances/'.$instanceId.'/token/'.$instanceToken;
    }

    private function endpointPath(string $path): string
    {
        return str_ends_with($this->baseUrl(), $path) ? '' : $path;
    }
}
