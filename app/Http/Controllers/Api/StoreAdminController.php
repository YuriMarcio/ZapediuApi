<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Support\Audit\AuditLogger;
use App\Support\Stores\StorePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ações administrativas de loja restritas ao master (createstore.md, "Ações do
 * card" / menu de três pontos) — marcar loja de teste, liberar acesso do
 * gerente/vendedor fora da janela de 15 dias, e ativar/desativar a operação da
 * loja. Cada ação grava auditoria.
 */
class StoreAdminController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function markTestStore(Request $request, Store $store): JsonResponse
    {
        $data = $request->validate(['is_test_store' => ['required', 'boolean']]);
        $before = $store->is_test_store;
        $store->update(['is_test_store' => $data['is_test_store']]);

        $this->audit->log('store.mark_test_store', [
            'company_id' => $store->company_id,
            'entity_type' => 'store',
            'entity_id' => $store->id,
            'changes' => ['is_test_store' => ['old' => $before, 'new' => $store->is_test_store]],
        ], $request);

        return $this->respond($store);
    }

    public function toggleSellerAccess(Request $request, Store $store): JsonResponse
    {
        $data = $request->validate(['seller_access_manually_enabled' => ['required', 'boolean']]);
        $before = $store->seller_access_manually_enabled;
        $store->update(['seller_access_manually_enabled' => $data['seller_access_manually_enabled']]);

        $this->audit->log('store.toggle_seller_access', [
            'company_id' => $store->company_id,
            'entity_type' => 'store',
            'entity_id' => $store->id,
            'changes' => ['seller_access_manually_enabled' => ['old' => $before, 'new' => $store->seller_access_manually_enabled]],
        ], $request);

        return $this->respond($store);
    }

    public function setActive(Request $request, Store $store): JsonResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $before = $store->is_active;
        $store->update(['is_active' => $data['is_active']]);

        $this->audit->log($data['is_active'] ? 'store.reactivate' : 'store.deactivate', [
            'company_id' => $store->company_id,
            'entity_type' => 'store',
            'entity_id' => $store->id,
            'changes' => ['is_active' => ['old' => $before, 'new' => $store->is_active]],
        ], $request);

        return $this->respond($store);
    }

    private function respond(Store $store): JsonResponse
    {
        StorePresenter::loadRelations($store);

        return response()->json(StorePresenter::toArray($store));
    }
}
