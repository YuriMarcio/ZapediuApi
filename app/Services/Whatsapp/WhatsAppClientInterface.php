<?php
// app/Services/WhatsApp/WhatsAppClientInterface.php
namespace App\Services\WhatsApp;

interface WhatsAppClientInterface
{
    // Mensagens básicas
    public function sendText(string $phone, string $message): array;
    public function replyMessage(string $phone, string $message, string $messageId): array;

    // Interatividade
    public function sendButtonList(string $phone, string $message, array $buttons): array;
    public function sendButtonActions(string $phone, string $message, array $buttons, ?string $title = null, ?string $footer = null): array;
    public function sendList(string $phone, string $message, string $buttonText, string $title, string $description, array $options): array;
    public function sendCarousel(string $phone, string $message, array $carousel): array;

    // Mídia
    public function sendImage(string $phone, string $imageUrl, string $caption = ''): array;
    public function sendAudio(string $phone, string $audioUrl): array;
    public function sendDocument(string $phone, string $documentUrl, string $fileName): array;

    // Catálogo (Z-API específico — fallback no Evolution)
    public function sendCatalog(string $phone, string $catalogPhone, array $options = []): array;
    public function sendProduct(string $phone, string $catalogPhone, string $productId): array;

    // Reação
    public function sendReaction(string $phone, string $messageId, string $emoji): array;
}