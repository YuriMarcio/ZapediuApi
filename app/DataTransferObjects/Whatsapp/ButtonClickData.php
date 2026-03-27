<?php

namespace App\DataTransferObjects\Whatsapp;

readonly class ButtonClickData
{
    public function __construct(
        public string $payload,
        public ?string $customerPhone,
        public ?string $messageId,
        public array $rawPayload,
    ) {
    }

    public static function fromWebhook(array $payload): ?self
    {
        $buttonPayload = data_get($payload, 'buttonReply.id')
            ?? data_get($payload, 'buttonId')
            ?? data_get($payload, 'selectedButtonId');

        if (! is_string($buttonPayload) || trim($buttonPayload) === '') {
            return null;
        }

        return new self(
            payload: strtolower(trim($buttonPayload)),
            customerPhone: data_get($payload, 'phone'),
            messageId: data_get($payload, 'messageId'),
            rawPayload: $payload,
        );
    }
}
