<?php
// app/Services/Whatsapp/WhatsAppClientInterface.php
namespace App\Services\Whatsapp;

/**
 * Contrato comum implementado por ZapiClient (chamada direta à Z-API) e FlowBridgeClient
 * (chamada ao gateway FlowBridge, que por sua vez fala com Evolution/Z-API/Meta). Só declara
 * os métodos realmente usados pelos fluxos de chatbot/pedidos/motoboy — ver
 * App\Services\Zapi\ZapiClient e App\Services\Whatsapp\FlowBridgeClient.
 */
interface WhatsAppClientInterface
{
    public function sendText(string $phone, string $message): array;

    public function sendButtonActions(string $phone, string $message, array $buttons, ?string $title = null, ?string $footer = null): array;

    public function sendList(string $phone, string $message, string $buttonText, string $title, string $description, array $options): array;

    public function sendCarousel(string $phone, string $message, array $carousel): array;

    // Catálogo do WhatsApp — recurso exclusivo da Z-API, sem equivalente no FlowBridge/Evolution.
    // FlowBridgeClient repassa essas duas chamadas direto para a Z-API por baixo.
    public function sendCatalog(string $phone, string $catalogPhone, array $options = []): array;
    public function sendProduct(string $phone, string $catalogPhone, string $productId): array;
}