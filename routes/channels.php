<?php

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/**
 * Canal do painel do lojista. É por COMPANY (não por store) porque o tenant do sistema é a
 * company — `Order` usa o trait BelongsToCompany e `OrderService::list()/summary()` não
 * filtram por `store_id`, ou seja a tela já soma todas as lojas da empresa. Um canal por
 * loja entregaria menos do que o painel exibe. O payload do evento carrega `store_id`, então
 * filtrar por loja no cliente (ou somar um canal `orders.store.{id}`) fica aditivo.
 */
Broadcast::channel('orders.company.{companyId}', function (User $user, int $companyId): bool {
    // Master acompanha qualquer empresa — mesma regra do middleware EnsureRole, que libera
    // master antes de checar papéis.
    if ((string) $user->role === 'master') {
        return true;
    }

    if ((int) ($user->company_id ?? 0) === $companyId) {
        return true;
    }

    // Gerente/vendedor não têm company_id próprio: o vínculo mora em companies.manager_id /
    // companies.seller_id — mesma checagem de StoreController::ensureStoreAccess.
    return match ((string) $user->role) {
        'manager' => Company::query()->whereKey($companyId)->where('manager_id', $user->id)->exists(),
        'seller' => Company::query()->whereKey($companyId)->where('seller_id', $user->id)->exists(),
        default => false,
    };
});
