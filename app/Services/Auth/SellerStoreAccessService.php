<?php

namespace App\Services\Auth;

use App\Models\Store;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Tymon\JWTAuth\Facades\JWTAuth;

class SellerStoreAccessService
{
    public function authenticateSeller(string $email, string $sellerCode): User
    {
        $seller = User::query()
            ->where('email', $email)
            ->where('role', 'seller')
            ->where('seller_code', $sellerCode)
            ->first();

        if ($seller === null || ! $this->isActiveSeller($seller)) {
            throw new UnauthorizedHttpException('', 'Credenciais invalidas.');
        }

        return $seller;
    }

    public function listAccessibleStoresForSeller(User $seller): Collection
    {
        return $this->baseSellerStoresQuery($seller)
            ->where('stores.created_at', '>=', $this->accessWindowStart())
            ->orderByDesc('stores.created_at')
            ->orderByDesc('stores.id')
            ->get();
    }

    public function issueStoreAccessTokens(User $seller, int $storeId): array
    {
        $store = $this->baseSellerStoresQuery($seller)->find($storeId);

        if ($store === null) {
            throw new NotFoundHttpException('Loja nao encontrada para este vendedor.');
        }

        if ($store->created_at === null || $store->created_at->lt($this->accessWindowStart())) {
            throw new AccessDeniedHttpException('A janela de acesso desta loja expirou.');
        }

        /** @var User|null $owner */
        $owner = $store->owner;

        if ($owner === null) {
            throw new NotFoundHttpException('Loja sem usuario proprietario vinculado.');
        }

        $accessTtl = 60;

        JWTAuth::factory()->setTTL($accessTtl);
        $accessToken = JWTAuth::fromUser($owner);

        JWTAuth::factory()->setTTL(60 * 24 * 30);
        $refreshToken = JWTAuth::fromUser($owner);

        return [
            'store' => $store,
            'owner' => $owner,
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in' => $accessTtl * 60,
            'refresh_expires_in' => 60 * 24 * 30 * 60,
            'token_type' => 'Bearer',
        ];
    }

    private function baseSellerStoresQuery(User $seller)
    {
        return Store::query()
            ->withoutGlobalScopes()
            ->with(['owner:id,name,email'])
            ->select('stores.*')
            ->join('companies', 'companies.id', '=', 'stores.company_id')
            ->where('companies.seller_id', $seller->id)
            ->where('companies.is_active', true)
            ->where('stores.is_active', true);
    }

    private function accessWindowStart(): CarbonInterface
    {
        $days = max(1, (int) Config::get('auth.seller_store_access_window_days', 15));

        return now()->subDays($days);
    }

    private function isActiveSeller(User $seller): bool
    {
        return $seller->role === 'seller';
    }
}