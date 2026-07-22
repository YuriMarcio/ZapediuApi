<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommercialSellerManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_create_list_and_block_own_seller(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);

        $response = $this->actingAs($manager, 'api')->postJson('/tenant/sellers', [
            'name' => 'Vendedor da Região',
            'email' => 'vendedor.regiao@example.com',
            'phone' => '(21) 99999-0000',
            'commercial_zone' => 'Copacabana',
            'monthly_store_goal' => 12,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.commercial_zone', 'Copacabana')
            ->assertJsonPath('data.monthly_store_goal', 12)
            ->assertJsonPath('data.is_active', true);

        $sellerId = $response->json('data.id');

        $this->actingAs($manager, 'api')->getJson('/tenant/sellers')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $sellerId);

        $this->actingAs($manager, 'api')->patchJson("/tenant/sellers/{$sellerId}/status", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('users', ['id' => $sellerId, 'manager_id' => $manager->id, 'is_active' => false]);
    }

    public function test_manager_cannot_manage_another_managers_seller(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);
        $otherManager = User::factory()->create(['role' => 'manager']);
        $seller = User::factory()->create(['role' => 'seller', 'manager_id' => $otherManager->id, 'seller_code' => 'OTHER-SELLER']);

        $this->actingAs($manager, 'api')->patchJson("/tenant/sellers/{$seller->id}/status", ['is_active' => false])
            ->assertNotFound();
    }
}
