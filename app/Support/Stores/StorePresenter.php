<?php

namespace App\Support\Stores;

use App\Models\Store;

/**
 * Fonte única do payload de loja usado pelo card do portfólio (gerente) e pela
 * listagem do master — os dois consomem o mesmo endpoint (GET /tenant/stores),
 * então o formato tem que servir aos dois em vez de duplicar em cada controller.
 */
class StorePresenter
{
    /**
     * Espera `$store` carregada com: company.wallet, company.seller, company.manager,
     * owner, withCount('orders'), withCount(['products as active_products_count' =>
     * fn ($q) => $q->available()]).
     */
    public static function toArray(Store $store): array
    {
        $data = $store->toArray();
        $data['store_id'] = $store->id;
        $data['size_template'] = $store->sizeTemplate();

        $data['is_test_store'] = (bool) $store->is_test_store;
        $data['seller_access_manually_enabled'] = (bool) $store->seller_access_manually_enabled;
        $data['mp_connected'] = (bool) $store->company?->wallet?->hasMpIntegration();
        $data['whatsapp_visible'] = $store->isEligibleForWhatsapp();
        $data['access_active'] = $store->hasActiveManagerAccess();
        $data['access_expires_at'] = $store->seller_access_manually_enabled || $store->created_at === null
            ? null
            : $store->created_at->copy()->addDays(max(1, (int) config('auth.seller_store_access_window_days', 15)))->toIso8601String();
        $data['products_count'] = (int) ($store->active_products_count ?? 0);
        $data['orders_count'] = (int) ($store->orders_count ?? 0);
        $data['revenue_total'] = (float) ($store->orders_sum_total ?? 0);
        $data['seller'] = $store->company?->seller ? ['id' => $store->company->seller->id, 'name' => $store->company->seller->name] : null;
        $data['manager'] = $store->company?->manager ? ['id' => $store->company->manager->id, 'name' => $store->company->manager->name] : null;
        $data['operation_name'] = $store->company?->operation?->name;

        return $data;
    }

    /**
     * Carrega as relations/counts que toArray() precisa. Funciona tanto num Model
     * único quanto numa Collection (Eloquent expõe load()/loadCount() nos dois),
     * então serve pro show() (um Store) e pro index() (Collection já materializada
     * por StoreOnboardingService::listForUser()).
     *
     * @template TStores of \App\Models\Store|\Illuminate\Support\Collection
     * @param TStores $stores
     * @return TStores
     */
    public static function loadRelations($stores)
    {
        return $stores
            ->load(['owner:id,name,email', 'company.wallet', 'company.seller:id,name', 'company.manager:id,name', 'company.operation:id,name'])
            ->loadCount('orders')
            ->loadCount(['products as active_products_count' => fn ($q) => $q->available()])
            ->loadSum('orders', 'total');
    }
}
