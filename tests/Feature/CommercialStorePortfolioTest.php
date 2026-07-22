<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommercialStorePortfolioTest extends TestCase
{
    use RefreshDatabase;

    public function test_seller_creates_and_lists_own_store_portfolio(): void
    {
        $this->createPlan();
        $seller = User::factory()->create(['role' => 'seller', 'seller_code' => 'SELLER-001']);

        $this->actingAs($seller, 'api')
            ->postJson('/tenant/onboarding/stores', $this->payload('Loja do Vendedor'))
            ->assertCreated()
            ->assertJsonPath('data.store.name', 'Loja do Vendedor');

        $company = Company::query()->where('trade_name', 'Loja do Vendedor')->firstOrFail();
        $this->assertSame($seller->id, $company->seller_id);

        $this->actingAs($seller, 'api')
            ->getJson('/tenant/stores')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Loja do Vendedor');
    }

    public function test_manager_creates_and_lists_managed_store_portfolio(): void
    {
        $this->createPlan();
        $manager = User::factory()->create(['role' => 'manager']);

        $this->actingAs($manager, 'api')
            ->postJson('/tenant/onboarding/stores', $this->payload('Loja do Gerente'))
            ->assertCreated()
            ->assertJsonPath('data.store.name', 'Loja do Gerente');

        $company = Company::query()->where('trade_name', 'Loja do Gerente')->firstOrFail();
        $this->assertSame($manager->id, $company->manager_id);

        $this->actingAs($manager, 'api')
            ->getJson('/tenant/stores')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Loja do Gerente');
    }

    private function createPlan(): void
    {
        Plan::query()->create([
            'slug' => 'diario',
            'name' => 'Diário',
            'tagline' => 'Plano diário',
            'pitch' => 'Plano de teste',
            'fee_percent' => 7.9,
            'fee_fixed' => 0,
            'features' => [],
            'is_active' => true,
            'sort_order' => 1,
        ]);
    }

    private function payload(string $storeName): array
    {
        return [
            'plan_slug' => 'diario',
            'owner' => [
                'name' => 'Proprietário '.$storeName,
                'email' => str()->slug($storeName).'@example.com',
                'phone' => '(21) 99999-9999',
                'cpf' => '123.456.789-09',
            ],
            'company' => [
                'trade_name' => $storeName,
                'legal_name' => '',
                'document' => '',
                'phone' => '(21) 99999-9999',
                'whatsapp' => '(21) 99999-9999',
            ],
            'store' => [
                'name' => $storeName,
                'slug' => str()->slug($storeName),
                'timezone' => 'America/Sao_Paulo',
                'business_type' => 'outros',
            ],
            'address' => [
                'zipcode' => '22000-000',
                'street' => 'Rua de Teste',
                'number' => '100',
                'district' => 'Centro',
                'complement' => '',
                'city' => 'Rio de Janeiro',
                'state' => 'RJ',
            ],
            'business_hours' => [[
                'day' => 'monday',
                'enabled' => true,
                'open' => '09:00',
                'close' => '18:00',
            ]],
        ];
    }
}
