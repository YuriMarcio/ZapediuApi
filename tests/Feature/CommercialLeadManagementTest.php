<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommercialLeadManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_create_update_and_note_a_lead(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);
        $seller = User::factory()->create(['role' => 'seller', 'manager_id' => $manager->id, 'seller_code' => 'LEAD-SELLER']);

        $response = $this->actingAs($manager, 'api')->postJson('/tenant/leads', [
            'name' => 'Pizzaria do Bairro',
            'category' => 'Pizzas',
            'zone' => 'Copacabana',
            'assigned_seller_id' => $seller->id,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.stage', 'novo_lead')
            ->assertJsonPath('data.assigned_seller_name', $seller->name);

        $leadId = $response->json('data.id');

        $this->actingAs($manager, 'api')->patchJson("/tenant/leads/{$leadId}", ['stage' => 'primeiro_contato'])
            ->assertOk()
            ->assertJsonPath('data.stage', 'primeiro_contato');

        $this->actingAs($manager, 'api')->postJson("/tenant/leads/{$leadId}/notes", ['text' => 'Ligação realizada.'])
            ->assertOk()
            ->assertJsonPath('data.notes.0.text', 'Ligação realizada.');

        $this->actingAs($manager, 'api')->getJson('/tenant/leads')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Pizzaria do Bairro');
    }

    public function test_manager_cannot_assign_lead_to_another_regions_seller(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);
        $otherManager = User::factory()->create(['role' => 'manager']);
        $otherSeller = User::factory()->create(['role' => 'seller', 'manager_id' => $otherManager->id, 'seller_code' => 'OTHER-LEAD']);

        $this->actingAs($manager, 'api')->postJson('/tenant/leads', [
            'name' => 'Lead inválido',
            'assigned_seller_id' => $otherSeller->id,
        ])->assertStatus(422);
    }

    public function test_seller_manages_only_own_leads(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);
        $seller = User::factory()->create(['role' => 'seller', 'manager_id' => $manager->id, 'seller_code' => 'OWN-LEAD']);
        $otherSeller = User::factory()->create(['role' => 'seller', 'manager_id' => $manager->id, 'seller_code' => 'OTHER-OWN-LEAD']);

        $response = $this->actingAs($seller, 'api')->postJson('/tenant/leads', [
            'name' => 'Lead do vendedor',
            'category' => 'Lanches',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.assigned_seller_id', $seller->id);

        $this->actingAs($otherSeller, 'api')->getJson('/tenant/leads')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
