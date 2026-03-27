<?php

namespace App\Models\Scopes;

use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class CompanyScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public function apply(Builder $builder, Model $model): void
    {
        /** @var TenantContext $tenant */
        $tenant = app(TenantContext::class);

        if (! $tenant->hasCompany()) {
            return;
        }

        $builder->where($model->getTable().'.company_id', $tenant->companyId());
    }
}
