<?php

namespace App\Services\Whatsapp;

use App\Models\Company;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Fala com o FlowBridge (github.com/YuriMarcio/Zap-Conection), único provedor de WhatsApp da
 * aplicação. O FlowBridge é um gateway HTTP que normaliza Evolution API, Z-API e Meta Cloud
 * API atrás de uma única rota de mensagens (POST /v1/instances/{id}/messages/{type}) — qual
 * provider está por baixo é configuração do lado do FlowBridge, não deste client.
 */
class FlowBridgeClient implements WhatsAppClientInterface
{
    public function __construct(private readonly TenantContext $tenant)
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
                'body' => (string) ($card['body'] ?? $card['text'] ?? $message),
                'footer' => $card['footer'] ?? null,
                'imageUrl' => $card['imageUrl'] ?? $card['image'] ?? null,
                'buttons' => $this->mapButtons($card['buttons'] ?? []),
            ], fn (mixed $value): bool => $value !== null && $value !== []),
            $carousel
        ));

        \Illuminate\Support\Facades\Log::info('sendCarousel payload de saída', ['cards_count' => count($cards), 'cards' => $cards]);

        return $this->sendMessage('carousel', $phone, [
            'content' => [
                'body' => $message,
                'cards' => $cards,
            ],
        ]);
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function mapButtons(array $buttons): array
    {
        return array_values(array_map($this->mapButton(...), $buttons));
    }

    /**
     * Cada tipo de botão da Evolution API exige um campo próprio além de type/displayText:
     * id (reply), url (url), phoneNumber (call) ou copyCode (copy). Mandar todos como "reply"
     * é o que fazia o carrossel ser rejeitado com 400 e cair no fallback de lista simples.
     */
    private function mapButton(array $button): array
    {
        $type = strtolower((string) ($button['type'] ?? 'reply'));
        $displayText = (string) ($button['displayText'] ?? $button['label'] ?? '');

        return match ($type) {
            'url' => [
                'type' => 'url',
                'displayText' => $displayText,
                'url' => (string) ($button['url'] ?? ''),
            ],
            'call' => [
                'type' => 'call',
                'displayText' => $displayText,
                'phoneNumber' => (string) ($button['phoneNumber'] ?? $button['phone'] ?? ''),
            ],
            'copy' => [
                'type' => 'copy',
                'displayText' => $displayText,
                'copyCode' => (string) ($button['copyCode'] ?? $button['code'] ?? ''),
            ],
            default => [
                'type' => 'reply',
                'id' => (string) ($button['id'] ?? ''),
                'displayText' => $displayText,
            ],
        };
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

    /**
     * Resolve a instância a responder por: (1) a instância que recebeu a mensagem atual, setada
     * direto no TenantContext pelo webhook — funciona mesmo sem nenhuma loja vinculada à
     * operação, já que uma resposta só precisa sair pelo mesmo número que recebeu; (2) se não
     * houver isso (ex.: fluxo de confirmação de entregador, que não passa pelo webhook),
     * company -> operation -> whatsapp_session; (3) o ID fixo do config como último fallback.
     * Usar um valor fixo sozinho fazia toda resposta sair pela mesma instância (frequentemente
     * errada/morta), não importa qual operação de fato recebeu a mensagem.
     */
    private function instanceId(): string
    {
        if ($this->tenant->whatsappInstanceId()) {
            return $this->tenant->whatsappInstanceId();
        }

        if ($this->tenant->hasCompany()) {
            $instanceId = Company::query()
                ->with('operation.whatsappSession')
                ->find($this->tenant->companyId())
                ?->operation
                ?->whatsappSession
                ?->flowbridge_instance_id;

            if ($instanceId) {
                return $instanceId;
            }
        }

        return (string) config('services.flowbridge.instance_id');
    }
}
