<?php

namespace App\Services\Onboarding;

use App\Mail\ManagerOnboardingMail;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;

class OnboardingService
{
    private const DEFAULT_MANAGER_OWNER_PASSWORD = 'Zapediu@2026';

    public function create(array $data, bool $assignDefaultOwnerPassword = false, bool $includeAuth = true, ?User $manager = null): array
    {
        return DB::transaction(function () use ($data, $assignDefaultOwnerPassword, $includeAuth, $manager) {
            /** @var User|null $seller */
            $seller = null;
            if (! empty($data['seller_code'])) {
                $seller = User::query()
                    ->where('seller_code', $data['seller_code'])
                    ->where('role', 'seller')
                    ->firstOrFail();
            }

            $plan = Plan::query()
                ->where('slug', $data['plan_slug'])
                ->where('is_active', true)
                ->firstOrFail();

            $company = Company::query()->create([
                'name' => $data['company']['trade_name'],
                'trade_name' => $data['company']['trade_name'],
                'legal_name' => $data['company']['legal_name'] ?? null,
                'document' => $data['company']['document'] ?? null,
                'phone' => $data['company']['phone'] ?? null,
                'whatsapp' => $data['company']['whatsapp'],
                'slug' => $this->uniqueCompanySlug($data['company']['trade_name']),
                'seller_id' => $seller?->id,
                'manager_id' => $manager?->id,
                'operation_id' => $data['operation_id'] ?? null,
                'plan_id' => $plan->id,
                'segment' => 'food',
                'is_active' => true,
                'api_token' => Str::random(80),
            ]);

            // Cria a wallet vinculada à empresa
            $company->wallet()->create([
                'plan_id' => $plan->id,
                'balance_pix' => 0,
                'balance_card' => 0,
                'balance_total' => 0,
                'is_enabled_withdrawal' => false,
                'is_active' => false,
            ]);

            /** @var User $owner */
            $owner = User::query()->create([
                'company_id' => $company->id,
                'name' => $data['owner']['name'],
                'email' => $data['owner']['email'],
                'phone' => $data['owner']['phone'],
                'cpf' => $data['owner']['cpf'],
                'password' => Hash::make($assignDefaultOwnerPassword ? self::DEFAULT_MANAGER_OWNER_PASSWORD : $data['owner']['password']),
                'role' => 'owner',
                'is_admin' => false,
            ]);

            $storeSlug = isset($data['store']['slug']) && $data['store']['slug'] !== ''
                ? $data['store']['slug']
                : $this->uniqueStoreSlug($data['store']['name']);

            /** @var Store $store */
            $store = Store::query()->create([
                'company_id' => $company->id,
                'user_id' => $owner->id,
                'name' => $data['store']['name'],
                'slug' => $storeSlug,
                'timezone' => $data['store']['timezone'] ?? 'America/Sao_Paulo',
                'business_type' => $data['store']['business_type'],
                'max_flavors' => in_array($data['store']['business_type'], ['pizzaria', 'acaiteria'], true) ? 2 : 1,
                'whatsapp_phone' => $data['company']['whatsapp'],
                'phone' => $data['company']['phone'] ?? null,
                'cnpj' => $data['company']['document'] ?? null,
                'legal_name' => $data['company']['legal_name'] ?? null,
                'zip_code' => $data['address']['zipcode'],
                'street' => $data['address']['street'],
                'number' => $data['address']['number'],
                'neighborhood' => $data['address']['district'],
                'complement' => $data['address']['complement'] ?? null,
                'city' => $data['address']['city'],
                'state' => $data['address']['state'],
                'business_hours' => $data['business_hours'],
                // Loja sempre nasce em onboarding/oculta (createstore.md, "Fluxo
                // esperado") — só fica visível no WhatsApp depois de conectar o Mercado
                // Pago (ou ser marcada is_test_store pelo master). Regra de negócio, não
                // depende de ambiente. Ver Store::scopeVisibleOnWhatsapp().
                'is_active' => false,
            ]);

            if ($assignDefaultOwnerPassword) {
                DB::afterCommit(fn () => $this->sendManagerOnboardingEmail($owner, $company));
            }

            $result = [
                'message' => 'Loja criada com sucesso.',
                'data' => [
                    'owner' => [
                        'id' => $owner->id,
                        'name' => $owner->name,
                        'email' => $owner->email,
                    ],
                    'company' => [
                        'id' => $company->id,
                        'trade_name' => $company->trade_name,
                        'plan' => [
                            'slug' => $plan->slug,
                            'name' => $plan->name,
                        ],
                    ],
                    'store' => [
                        'id' => $store->id,
                        'name' => $store->name,
                    ],
                ],
            ];

            if (! $includeAuth) {
                return $result;
            }

            $accessToken = JWTAuth::fromUser($owner);
            JWTAuth::factory()->setTTL(60 * 24 * 30);
            $refreshToken = JWTAuth::fromUser($owner);

            $result['auth'] = [
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'expires_in' => 3600,
                'refresh_expires_in' => 60 * 24 * 30 * 60,
                'token_type' => 'Bearer',
                'user' => [
                    'id' => $owner->id,
                    'name' => $owner->name,
                    'email' => $owner->email,
                ],
            ];

            return $result;
        });
    }

    private function sendManagerOnboardingEmail(User $owner, Company $company): void
    {
        try {
            Mail::to($owner->email)->send(
                new ManagerOnboardingMail($owner, $company, self::DEFAULT_MANAGER_OWNER_PASSWORD),
            );
        } catch (\Throwable $exception) {
            Log::warning('Failed to send manager onboarding email.', [
                'owner_id' => $owner->id,
                'email' => $owner->email,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function uniqueCompanySlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base !== '' ? $base : 'empresa';
        $suffix = 1;

        while (Company::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    private function uniqueStoreSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base !== '' ? $base : 'loja';
        $suffix = 1;

        while (Store::query()->withoutGlobalScopes()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
