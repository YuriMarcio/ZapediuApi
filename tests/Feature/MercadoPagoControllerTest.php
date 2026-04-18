<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class MercadoPagoControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_pix_requires_code_and_token(): void
    {
        $response = $this->postJson('/public/checkout/pix', []);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['code', 'token']);
    }

    public function test_create_pix_returns_404_if_order_not_found(): void
    {
        $response = $this->postJson('/public/checkout/pix', [
            'code' => 'INVALID-CODE',
            'token' => 'some-token',
        ]);

        $response->assertStatus(404)
                 ->assertJson(['message' => 'Pedido não encontrado.']);
    }

    public function test_create_pix_returns_403_if_token_is_invalid(): void
    {
        $company = Company::factory()->create();
        $store = Store::factory()->create(['company_id' => $company->id]);
        $user = User::factory()->create(['company_id' => $company->id]);
        
        $order = Order::create([
            'company_id' => $company->id,
            'store_id' => $store->id,
            'user_id' => $user->id,
            'code' => 'ZAP-TEST-123',
            'status' => 'pending',
            'payment_status' => 'pending',
            'total' => 100.00,
            'raw_payload' => [
                'checkout' => [
                    'public_token' => 'correct-token',
                ]
            ],
        ]);

        $response = $this->postJson('/public/checkout/pix', [
            'code' => $order->code,
            'token' => 'wrong-token',
        ]);

        $response->assertStatus(403)
                 ->assertJson(['message' => 'Token de checkout inválido.']);
    }

    public function test_create_pix_returns_409_if_already_paid(): void
    {
        $company = Company::factory()->create();
        $store = Store::factory()->create(['company_id' => $company->id]);
        $user = User::factory()->create(['company_id' => $company->id]);
        
        $order = Order::create([
            'company_id' => $company->id,
            'store_id' => $store->id,
            'user_id' => $user->id,
            'code' => 'ZAP-TEST-123',
            'status' => 'pending',
            'payment_status' => 'paid',
            'total' => 100.00,
            'raw_payload' => [
                'checkout' => [
                    'public_token' => 'correct-token',
                ]
            ],
        ]);

        $response = $this->postJson('/public/checkout/pix', [
            'code' => $order->code,
            'token' => 'correct-token',
        ]);

        $response->assertStatus(409)
                 ->assertJson(['message' => 'Este pedido já possui pagamento confirmado.']);
    }
}
