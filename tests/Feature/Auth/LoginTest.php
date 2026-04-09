<?php

namespace Tests\Feature\Auth;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_with_own_password(): void
    {
        $company = Company::query()->create([
            'name' => 'Loja Centro',
            'slug' => 'loja-centro',
            'api_token' => 'token-loja-centro',
        ]);

        $user = User::factory()->create([
            'company_id' => $company->id,
            'email' => 'lojista@example.com',
            'password' => 'MinhaSenha123',
        ]);

        $response = $this->postJson('/auth/login', [
            'email' => $user->email,
            'password' => 'MinhaSenha123',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('user.email', $user->email)
            ->assertJsonPath('user.company_id', $company->id);
    }

    public function test_master_password_can_login_with_store_email(): void
    {
        Config::set('auth.master_password', 'PassMaster2026');

        $company = Company::query()->create([
            'name' => 'Loja Master',
            'slug' => 'loja-master',
            'api_token' => 'token-loja-master',
        ]);

        $user = User::factory()->create([
            'company_id' => $company->id,
            'email' => 'loja@example.com',
            'password' => 'SenhaDaLoja123',
        ]);

        $response = $this->postJson('/auth/login', [
            'email' => $user->email,
            'password' => 'PassMaster2026',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('user.email', $user->email)
            ->assertJsonPath('user.company_id', $company->id);
    }

    public function test_login_fails_with_invalid_password_when_master_password_is_not_configured(): void
    {
        Config::set('auth.master_password', '');

        $company = Company::query()->create([
            'name' => 'Loja Segura',
            'slug' => 'loja-segura',
            'api_token' => 'token-loja-segura',
        ]);

        $user = User::factory()->create([
            'company_id' => $company->id,
            'email' => 'segura@example.com',
            'password' => 'SenhaCorreta123',
        ]);

        $response = $this->postJson('/auth/login', [
            'email' => $user->email,
            'password' => 'PassMaster2026',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Credenciais invalidas.');
    }
}