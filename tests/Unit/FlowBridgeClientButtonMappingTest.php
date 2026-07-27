<?php

namespace Tests\Unit;

use App\Services\Whatsapp\FlowBridgeClient;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FlowBridgeClientButtonMappingTest extends TestCase
{
    public function test_sendCarousel_maps_each_button_type_to_its_own_field()
    {
        config([
            'services.flowbridge.base_url' => 'https://flowbridge.test',
            'services.flowbridge.instance_id' => 'instance-1',
            'services.flowbridge.api_key' => 'secret',
        ]);

        Http::fake(['*' => Http::response(['status' => 'sent'], 200)]);

        $client = new FlowBridgeClient(new TenantContext(whatsappInstanceId: 'instance-1'));

        $client->sendCarousel('5511999999999', 'Confira nossas lojas:', [[
            'title' => 'Loja Exemplo',
            'buttons' => [
                ['type' => 'REPLY', 'id' => 'view_menu_loja', 'label' => 'Ver Cardápio'],
                ['type' => 'URL', 'url' => 'https://pay.example.com/abc', 'label' => 'Pagar'],
                ['type' => 'CALL', 'phoneNumber' => '+5511988887777', 'label' => 'Ligar'],
                ['type' => 'COPY', 'copyCode' => 'PROMO10', 'label' => 'Copiar cupom'],
            ],
        ]]);

        Http::assertSent(function ($request) {
            $buttons = $request->data()['content']['cards'][0]['buttons'];

            return $buttons[0] === ['type' => 'reply', 'id' => 'view_menu_loja', 'displayText' => 'Ver Cardápio']
                && $buttons[1] === ['type' => 'url', 'displayText' => 'Pagar', 'url' => 'https://pay.example.com/abc']
                && $buttons[2] === ['type' => 'call', 'displayText' => 'Ligar', 'phoneNumber' => '+5511988887777']
                && $buttons[3] === ['type' => 'copy', 'displayText' => 'Copiar cupom', 'copyCode' => 'PROMO10'];
        });
    }

    public function test_sendCarousel_maps_text_and_image_keys_used_by_handlers()
    {
        config([
            'services.flowbridge.base_url' => 'https://flowbridge.test',
            'services.flowbridge.instance_id' => 'instance-1',
            'services.flowbridge.api_key' => 'secret',
        ]);

        Http::fake(['*' => Http::response(['status' => 'sent'], 200)]);

        $client = new FlowBridgeClient(new TenantContext(whatsappInstanceId: 'instance-1'));

        $client->sendCarousel('5511999999999', 'Confira nossas lojas:', [[
            'text' => 'Loja Exemplo ⭐ 4.8',
            'image' => 'https://cdn.example.com/loja.jpg',
            'buttons' => [
                ['type' => 'REPLY', 'id' => 'view_menu_loja', 'label' => 'Ver Cardápio'],
            ],
        ]]);

        Http::assertSent(function ($request) {
            $card = $request->data()['content']['cards'][0];

            return $card['body'] === 'Loja Exemplo ⭐ 4.8'
                && $card['imageUrl'] === 'https://cdn.example.com/loja.jpg';
        });
    }
}
