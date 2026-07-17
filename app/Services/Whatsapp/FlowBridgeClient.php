<?php

namespace App\Services\Whatsapp;

use App\Services\Zapi\ZapiClient;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Fala com o FlowBridge (github.com/YuriMarcio/Zap-Conection) em vez de bater direto na
 * Z-API. O FlowBridge é um gateway HTTP que normaliza Evolution API, Z-API e Meta Cloud API
 * atrás de uma única rota de mensagens (POST /v1/instances/{id}/messages/{type}) — qual
 * provider está por baixo é configuração do lado do FlowBridge, não deste client.
 *
 * sendCatalog/sendProduct não têm equivalente em nenhum provider do FlowBridge (catálogo do
 * WhatsApp é recurso exclusivo da Z-API), então essas duas chamadas continuam indo direto
 * para a Z-API via $catalogFallback.
 */
class FlowBridgeClient implements WhatsAppClientInterface
{
    public function __construct(private readonly ZapiClient $catalogFallback)
    {
    }

    public function sendText(string $phone, string $message): array
    {
        return $this->sendMessage('text', $phone, ['text' => $message]);
    }

    public function sendButtonActions(string $phone, string $message, array $buttons, ?string $title = null, ?string $footer = null): array
    {
        $content = array_filter([
            'title' => $title,
            'body' => $message,
            'footer' => $footer,
            'buttons' => $this->mapButtons($buttons),
        ], fn (mixed $value): bool => $value !== null);

        return $this->sendMessage('buttons', $phone, ['content' => $content]);
    }

    public function sendList(string $phone, string $message, string $buttonText, string $title, string $description, array $options): array
    {
        $rows = array_values(array_map(
            fn (array $option): array => array_filter([
                'rowId' => (string) ($option['id'] ?? ''),
                'title' => (string) ($option['title'] ?? ''),
                'description' => $option['description'] ?? null,
            ], fn (mixed $value): bool => $value !== null),
            $options
        ));

        return $this->sendMessage('list', $phone, [
            'content' => [
                'title' => $title,
                'description' => $description !== '' ? $description : $message,
                'buttonText' => $buttonText,
                'sections' => [[
                    'title' => $title,
                    'rows' => $rows,
                ]],
            ],
        ]);
    }

    public function sendCarousel(string $phone, string $message, array $carousel): array
    {
        $cards = array_values(array_map(
            fn (array $card): array => array_filter([
                'title' => $card['title'] ?? null,
                'body' => (string) ($card['body'] ?? $message),
                'footer' => $card['footer'] ?? null,
                'imageUrl' => $card['imageUrl'] ?? null,
                'buttons' => $this->mapButtons($card['buttons'] ?? []),
            ], fn (mixed $value): bool => $value !== null && $value !== []),
            $carousel
        ));

        return $this->sendMessage('carousel', $phone, [
            'content' => [
                'body' => $message,
                'cards' => $cards,
            ],
        ]);
    }

    public function sendCatalog(string $phone, string $catalogPhone, array $options = []): array
    {
        return $this->catalogFallback->sendCatalog($phone, $catalogPhone, $options);
    }

    public function sendProduct(string $phone, string $catalogPhone, string $productId): array
    {
        return $this->catalogFallback->sendProduct($phone, $catalogPhone, $productId);
    }

    /**
     * @return array<int, array{id: string, displayText: string}>
     */
    private function mapButtons(array $buttons): array
    {
        return array_values(array_map(
            fn (array $button): array => [
                'id' => (string) ($button['id'] ?? ''),
                'displayText' => (string) ($button['displayText'] ?? $button['label'] ?? ''),
            ],
            $buttons
        ));
    }

    private function sendMessage(string $type, string $phone, array $payload): array
    {
        $response = $this->http()
            ->post("/v1/instances/{$this->instanceId()}/messages/{$type}", ['to' => $phone, ...$payload])
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
        return Http::baseUrl(rtrim((string) config('services.flowbridge.base_url'), '/'))
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('services.flowbridge.timeout', 15))
            ->connectTimeout(5)
            ->withHeaders([
                'x-api-key' => (string) config('services.flowbridge.api_key'),
            ]);
    }

    private function instanceId(): string
    {
        return (string) config('services.flowbridge.instance_id');
    }
}
