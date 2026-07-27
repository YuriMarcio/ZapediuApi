<?php

namespace App\Http\Controllers\Api\Tenant\Concerns;

use App\Models\Store;
use App\Support\Tenancy\TenantContext;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

trait ResolvesPizzaStore
{
    /**
     * Loja pizzaria do tenant atual. As rotas de configuração de pizzaria não recebem
     * um store_id explícito (assim como /tenant/company) — assume-se uma loja por empresa,
     * igual ao restante do painel.
     */
    private function resolvePizzaStore(TenantContext $tenant): Store
    {
        $store = Store::query()
            ->where('company_id', $tenant->companyId())
            ->where('business_type', 'pizzaria')
            ->orderBy('id')
            ->first();

        if ($store === null) {
            throw new NotFoundHttpException('Nenhuma loja pizzaria encontrada para esta empresa.');
        }

        return $store;
    }
}
