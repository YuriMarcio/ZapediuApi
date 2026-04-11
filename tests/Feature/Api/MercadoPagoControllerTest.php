<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MercadoPagoControllerTest extends TestCase
{
    public function test_pix_endpoint_returns_qr_code()
    {
        $response = $this->postJson('/api/pix', [
            'amount' => 56.41,
            'description' => 'Pedido Zapediu',
            'payer' => [
                'name' => 'Capivara Silva',
                'email' => 'cliente@email.com',
                'identification' => '12345678900',
            ],
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'qr_code_base64',
                'qr_code',
            ]);
    }

    public function test_card_endpoint_returns_approved_or_rejected()
    {
        $response = $this->postJson('/api/card', [
            'token' => 'FAKE_TOKEN',
            'amount' => 56.41,
            'description' => 'Pedido Zapediu',
            'payer' => [
                'name' => 'Capivara Silva',
                'email' => 'cliente@email.com',
                'identification' => '12345678900',
            ],
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'payment_id',
            ]);
    }
}
