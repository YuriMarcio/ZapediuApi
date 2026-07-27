<?php

namespace App\Services\Whatsapp;

/**
 * Traduz o DomainEvent MessageReceived do FlowBridge (payload.content = mensagem crua do
 * Baileys, repassada sem normalização pelo EvolutionProvider) para o formato "achatado" que
 * App\Services\Zapi\Support\PayloadExtractor e ProcessZapiWebhookJob já sabem processar
 * (phone, text.message, buttonReply.id) — assim o pipeline de chatbot inteiro (flows/handlers)
 * é reaproveitado sem duplicação, tanto faz se a mensagem veio da Z-API ou do FlowBridge.
 *
 * Cobre apenas o vocabulário do Baileys realmente emitido pela Evolution API hoje: texto
 * simples, texto estendido, resposta de botão e resposta de lista. Mensagens de mídia sem
 * legenda voltam null (sem equivalente de texto/botão pra alimentar os flows atuais).
 */
class FlowBridgeInboundPayloadMapper
{
    /**
     * @param array<string, mixed> $event Evento bruto recebido no callbackUrl (DomainEvent).
     * @return array<string, mixed>|null
     */
    public static function toZapiShape(array $event): ?array
    {
        $payload = $event['payload'] ?? [];
        $phone = (string) ($payload['from'] ?? '');
        if ($phone === '') {
            return null;
        }

        $content = is_array($payload['content'] ?? null) ? $payload['content'] : [];
        $text = self::extractText($content);
        $buttonReplyId = self::extractButtonReplyId($content);

        if ($text === null && $buttonReplyId === null) {
            return null;
        }

        return array_filter([
            'phone' => $phone,
            'messageId' => $payload['messageId'] ?? null,
            'instanceId' => $event['instanceId'] ?? null,
            'fromMe' => false,
            'momment' => self::occurredAtMs($event['occurredAt'] ?? null),
            'text' => $text !== null ? ['message' => $text, 'text' => $text] : null,
            'buttonReply' => $buttonReplyId !== null ? ['id' => $buttonReplyId] : null,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param array<string, mixed> $content
     */
    private static function extractText(array $content): ?string
    {
        $text = $content['conversation']
            ?? $content['extendedTextMessage']['text']
            ?? $content['imageMessage']['caption']
            ?? $content['videoMessage']['caption']
            ?? $content['documentMessage']['caption']
            ?? null;

        if ($text === null || trim((string) $text) === '') {
            return null;
        }

        return trim((string) $text);
    }

    /**
     * @param array<string, mixed> $content
     */
    private static function extractButtonReplyId(array $content): ?string
    {
        $id = $content['buttonsResponseMessage']['selectedButtonId']
            ?? $content['templateButtonReplyMessage']['selectedId']
            ?? $content['listResponseMessage']['singleSelectReply']['selectedRowId']
            ?? null;

        if ($id === null || trim((string) $id) === '') {
            return null;
        }

        return trim((string) $id);
    }

    private static function occurredAtMs(?string $occurredAt): ?int
    {
        if ($occurredAt === null) {
            return null;
        }

        try {
            return (int) (\Carbon\CarbonImmutable::parse($occurredAt)->getTimestampMs());
        } catch (\Throwable) {
            return null;
        }
    }
}
