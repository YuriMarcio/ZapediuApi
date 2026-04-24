<?php

namespace App\Services\Zapi\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class PayloadExtractor
{
    /**
     * Identifica o tipo de evento
     */
    public function eventType(array $payload): string
    {
        return (string) ($payload['event'] ?? $payload['type'] ?? 'message.received');
    }

    /**
     * Resolve o ID externo da mensagem
     */
    public function resolveExternalId(array $payload): string
    {
        return (string) ($payload['messageId'] ?? $payload['order']['id'] ?? '');
    }

    /**
     * Verifica se a mensagem foi enviada pelo próprio sistema
     */
    public function isOutgoingMessage(array $payload): bool
    {
        return (bool) ($payload['fromMe'] ?? false);
    }

    /**
     * Extrai o telefone de quem enviou a mensagem
     */
    public function resolveIncomingPhone(array $payload): ?string
    {
        $phone = $payload['phone'] ?? $payload['sender'] ?? null;

        if (!$phone) {
            return null;
        }

        return Str::before((string) $phone, '@');
    }

    /**
     * Extrai o texto da mensagem recebida
     */
    public function resolveIncomingMessageText(array $payload): ?string
    {
        $text = $payload['text']['message']
            ?? $payload['body']
            ?? $payload['caption']
            ?? null;

        if ($text === null || trim((string) $text) === '') {
            return null;
        }

        return trim((string) $text);
    }

    /**
     * Extrai o ID do botão pressionado (buttonReply ou listReply)
     */
    public function resolveButtonReplyId(array $payload): ?string
    {
        $id = $payload['buttonReply']['id']
            ?? $payload['listReply']['id']
            ?? $payload['buttonResponseMessage']['selectedButtonId']
            ?? null;

        if ($id === null || trim((string) $id) === '') {
            return null;
        }

        return strtolower(trim((string) $id));
    }

    /**
     * Resolve o slug da categoria a partir do payload
     * Exemplo: botão "category_pizzas" vira "pizzas"
     */
    public function resolveSelectedCategoryId(array $payload): ?string
    {
        $buttonId = $this->resolveButtonReplyId($payload);

        if ($buttonId === null || !str_starts_with($buttonId, 'category_')) {
            return null;
        }

        $slug = Str::after($buttonId, 'category_');

        return $slug !== '' ? $slug : null;
    }

    /**
     * Extrai atributos de entrega/contexto da mensagem
     */
    public function extractDeliveryAttributes(array $payload): array
    {
        return [
            'is_group'    => (bool) ($payload['isGroup'] ?? false),
            'instance_id' => $payload['instanceId'] ?? null,
            'timestamp'   => $this->resolveDateTime($payload),
        ];
    }

    /**
     * Recupera o estado atual do fluxo via Cache
     */
    public function flowState(string $phone): array
    {
        return Cache::get("zapi:flow:state:{$phone}", []);
    }

    /**
     * Converte o timestamp do payload para CarbonImmutable
     */
    public function resolveDateTime(array $payload): ?CarbonImmutable
    {
        $timestamp = $payload['momment'] ?? $payload['timestamp'] ?? null;

        if (!$timestamp) {
            return now()->toImmutable();
        }

        // Z-API envia timestamp em milissegundos (13 dígitos)
        return CarbonImmutable::createFromTimestampMs((int) $timestamp);
    }

    /**
     * Resolve o slug da categoria removendo o prefixo do botão
     */
    public function resolveCategorySlugFromButtonSlug(string $slug): string
    {
        return Str::after($slug, 'category_');
    }
}