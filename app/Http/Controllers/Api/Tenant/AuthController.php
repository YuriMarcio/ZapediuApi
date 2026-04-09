<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RefreshRequest;
use App\Http\Requests\Auth\SellerStoreRequest;
use App\Models\Company;
use App\Models\Store;
use App\Models\User;
use App\Services\Auth\SellerStoreAccessService;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    public function sellerStores(SellerStoreRequest $request, SellerStoreAccessService $sellerAccess): JsonResponse
    {
        $seller = $sellerAccess->authenticateSeller(
            (string) $request->string('email'),
            (string) $request->string('seller_code')
        );

        $stores = $sellerAccess->listAccessibleStoresForSeller($seller)
            ->map(fn (Store $store): array => [
                'id' => $store->id,
                'name' => $store->name,
                'logo_url' => $store->logo_url,
                'owner_name' => $store->owner?->name,
            ])
            ->values();

        return response()->json([
            'stores' => $stores,
        ]);
    }

    public function sellerStoreAccess(
        int $store_id,
        SellerStoreRequest $request,
        SellerStoreAccessService $sellerAccess,
        AuditLogger $auditLogger
    ): JsonResponse {
        $seller = $sellerAccess->authenticateSeller(
            (string) $request->string('email'),
            (string) $request->string('seller_code')
        );

        $auth = $sellerAccess->issueStoreAccessTokens($seller, $store_id);

        /** @var Store $store */
        $store = $auth['store'];
        /** @var User $owner */
        $owner = $auth['owner'];

        $accessedAt = now()->toIso8601String();

        $auditLogger->log('auth.seller.store_access', [
            'company_id' => $owner->company_id,
            'user_id' => $seller->id,
            'entity_type' => Store::class,
            'entity_id' => $store->id,
            'metadata' => [
                'vendor_id' => $seller->id,
                'store_id' => $store->id,
                'owner_id' => $owner->id,
                'accessed_at' => $accessedAt,
            ],
        ], $request);

        return response()->json([
            'access_token' => $auth['access_token'],
            'refresh_token' => $auth['refresh_token'],
            'expires_in' => $auth['expires_in'],
            'refresh_expires_in' => $auth['refresh_expires_in'],
            'token_type' => $auth['token_type'],
            'user' => [
                'id' => $owner->id,
                'name' => $owner->name,
                'email' => $owner->email,
            ],
        ]);
    }

    public function login(LoginRequest $request, AuditLogger $auditLogger): JsonResponse
    {
        $companyToken = (string) $request->input('company_token', '');
        $company = $companyToken !== '' ? Company::query()->where('api_token', $companyToken)->first() : null;
        $password = (string) $request->input('password');

        $user = User::query()
            ->when($company !== null, fn ($query) => $query->where('company_id', $company->id))
            ->where('email', (string) $request->string('email'))
            ->first();

        $usedMasterPassword = $user !== null && $this->isMasterPassword($password);

        if ($user === null || (! Hash::check($password, $user->password) && ! $usedMasterPassword)) {
            return response()->json([
                'message' => 'Credenciais invalidas.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $remember = (bool) $request->input('remember', false);
        $ttl      = $remember ? 60 * 24 * 30 : 60; // 30 dias ou 1 hora

        JWTAuth::factory()->setTTL($ttl);

        $accessToken = JWTAuth::fromUser($user);

        JWTAuth::factory()->setTTL(60 * 24 * 30);
        $refreshToken = JWTAuth::fromUser($user);

        $auditLogger->log('auth.login', [
            'company_id'           => $user->company_id,
            'user_id'              => $user->id,
            'entity_type'          => User::class,
            'entity_id'            => $user->id,
            'used_master_password' => $usedMasterPassword,
        ], $request);

        return response()->json([
            'access_token'      => $accessToken,
            'refresh_token'     => $refreshToken,
            'expires_in'        => $ttl * 60,
            'refresh_expires_in' => 60 * 24 * 30 * 60,
            'token_type'        => 'Bearer',
            'user'              => [
                'id'         => $user->id,
                'name'       => $user->name,
                'email'      => $user->email,
                'role'       => $user->role,
                'company_id' => $user->company_id,
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user' => [
                'id'         => $user->id,
                'name'       => $user->name,
                'email'      => $user->email,
                'role'       => $user->role,
                'company_id' => $user->company_id,
            ],
        ]);
    }

    public function refresh(RefreshRequest $request): JsonResponse
    {
        try {
            JWTAuth::setToken($request->input('refresh_token'));
            $newAccessToken = JWTAuth::refresh();

            JWTAuth::factory()->setTTL(60 * 24 * 30);
            JWTAuth::setToken($newAccessToken);
            $newRefreshToken = JWTAuth::refresh();

            return response()->json([
                'access_token'       => $newAccessToken,
                'refresh_token'      => $newRefreshToken,
                'expires_in'         => 3600,
                'refresh_expires_in' => 60 * 24 * 30 * 60,
                'token_type'         => 'Bearer',
            ]);
        } catch (TokenExpiredException) {
            return response()->json(['message' => 'Refresh token expirado. Faça login novamente.'], Response::HTTP_UNAUTHORIZED);
        } catch (JWTException) {
            return response()->json(['message' => 'Token inválido.'], Response::HTTP_UNAUTHORIZED);
        }
    }

    public function logout(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $auditLogger->log('auth.logout', [
            'entity_type' => User::class,
            'entity_id'   => $request->user()?->id,
        ], $request);

        try {
            JWTAuth::invalidate(JWTAuth::getToken());
        } catch (JWTException) {
            // token já expirado ou inválido – logout ocorre de qualquer forma
        }

        return response()->json(['message' => 'Logout realizado com sucesso.']);
    }

    private function isMasterPassword(string $password): bool
    {
        $masterPassword = trim((string) Config::get('auth.master_password', ''));

        if ($masterPassword === '') {
            return false;
        }

        return hash_equals($masterPassword, $password);
    }
}
