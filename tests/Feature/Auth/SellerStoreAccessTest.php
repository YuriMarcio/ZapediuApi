<?php

namespace Tests\Feature\Auth;

use App\Models\Company;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class SellerStoreAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_seller_can_list_only_recent_stores(): void
    {
        Config::set('auth.seller_store_access_window_days', 15);

        $seller = $this->createSeller();
        $recentStore = $this->createStoreForSeller($seller, 'Hamburgueria X', now()->subDays(2), 'https://cdn.example.com/hamburgueria.png');
        $this->createStoreForSeller($seller, 'Loja Expirada', now()->subDays(20));

        $response = $this->postJson('/auth/seller/stores', [
            'email' => $seller->email,
            'seller_code' => $seller->seller_code,
        ]);

        $response
            ->assertOk()
            ->assertJsonCount(1, 'stores')
            ->assertJsonPath('stores.0.id', $recentStore->id)
            ->assertJsonPath('stores.0.name', 'Hamburgueria X')
            ->assertJsonPath('stores.0.logo_url', 'https://cdn.example.com/hamburgueria.png')
            ->assertJsonPath('stores.0.owner_name', 'Owner Hamburgueria X');
    }

    public function test_seller_store_listing_returns_unauthorized_for_invalid_credentials(): void
    {
        $seller = $this->createSeller();

        $response = $this->postJson('/auth/seller/stores', [
            'email' => $seller->email,
            'seller_code' => 'CODIGO-ERRADO',
        ]);

        $response
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Credenciais invalidas.');
    }

    public function test_seller_can_exchange_store_access_for_owner_tokens(): void
    {
        Config::set('auth.seller_store_access_window_days', 15);

        $seller = $this->createSeller();
        $store = $this->createStoreForSeller($seller, 'Pizzaria Y', now()->subDays(1));

        $response = $this->postJson("/auth/seller/stores/{$store->id}/access", [
            'email' => $seller->email,
            'seller_code' => $seller->seller_code,
        ]);

        $response
            ->assertOk()
            ->assertJsonStructure([
                'access_token',
                'refresh_token',
                'expires_in',
                'refresh_expires_in',
                'token_type',
                'user' => ['id', 'name', 'email'],
            ])
            ->assertJsonPath('user.id', $store->owner->id)
            ->assertJsonPath('user.email', $store->owner->email)
            ->assertJsonPath('token_type', 'Bearer');

        $tokenOwner = JWTAuth::setToken((string) $response->json('access_token'))->toUser();

        $this->assertNotNull($tokenOwner);
        $this->assertSame($store->owner->id, $tokenOwner->id);
        $this->assertSame($store->company_id, $tokenOwner->company_id);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth.seller.store_access',
            'user_id' => $seller->id,
            'company_id' => $store->company_id,
            'entity_type' => Store::class,
            'entity_id' => $store->id,
        ]);

       

        // $this->assertSame($seller->id ['vendor_id'] ?? null);
        // $this->assertSame($store->id, $auditLog->metadata['store_id'] ?? null);
        // $this->assertNotEmpty($auditLog->metadata['accessed_at'] ?? null);
    }

    public function test_seller_store_access_returns_forbidden_when_store_window_has_expired(): void
    {
        Config::set('auth.seller_store_access_window_days', 15);

        $seller = $this->createSeller();
        $store = $this->createStoreForSeller($seller, 'Padaria Z', now()->subDays(16));

        $response = $this->postJson("/auth/seller/stores/{$store->id}/access", [
            'email' => $seller->email,
            'seller_code' => $seller->seller_code,
        ]);

        $response
            ->assertForbidden()
            ->assertJsonPath('message', 'A janela de acesso desta loja expirou.');
    }

    private function createSeller(): User
    {
        return User::factory()->create([
            'name' => 'Seller Test',
            'email' => 'seller@example.com',
            'password' => 'SenhaVendedor123',
            'role' => 'seller',
            'seller_code' => 'ABC123',
            'is_admin' => false,
        ]);
    }

    private function createStoreForSeller(User $seller, string $storeName, $createdAt, ?string $logoPath = null): Store
    {
        $company = Company::query()->create([
            'name' => $storeName,
            'trade_name' => $storeName,
            'slug' => str()->slug($storeName.'-'.$createdAt->timestamp),
            'api_token' => 'token-'.str()->slug($storeName).'-'.$createdAt->timestamp,
            'seller_id' => $seller->id,
            'is_active' => true,
        ]);

        $owner = User::factory()->create([
            'company_id' => $company->id,
            'name' => 'Owner '.$storeName,
            'email' => str()->slug($storeName).'+owner@example.com',
            'password' => 'SenhaOwner123',
            'role' => 'owner',
            'is_admin' => false,
        ]);

        return Store::query()->withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'user_id' => $owner->id,
            'name' => $storeName,
            'slug' => str()->slug($storeName).'-store-'.$createdAt->timestamp,
            'logo_path' => $logoPath,
            'is_active' => true,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}