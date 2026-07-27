<?php
// app/Services/Whatsapp/WhatsAppClientInterface.php
namespace App\Services\Whatsapp;

/**
 * Contrato implementado por App\Services\Whatsapp\FlowBridgeClient (único provedor de
 * WhatsApp da aplicação). Só declara os métodos realmente usados pelos fluxos de
 * chatbot/pedidos/motoboy.
 */
interface WhatsAppClientInterface
{
    public function sendText(string $phone, string $message): array;

    public function sendButtonActions(string $phone, string $message, array $buttons, ?string $title = null, ?string $footer = null): array;

    public function sendList(string $phone, string $message, string $buttonText, string $title, string $description, array $options): array;

    public function sendCarousel(string $phone, string $message, array $carousel): array;
}