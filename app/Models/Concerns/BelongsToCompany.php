<?php

namespace App\Models\Concerns;

use App\Models\Company;
use App\Models\Scopes\CompanyScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope());

        static::creating(function ($model): void {
            if (! isset($model->company_id) || $model->company_id === null) {
                /** @var TenantContext $tenant */
                $tenant = app(TenantContext::class);

                if ($tenant->hasCompany()) {
                    $model->company_id = $tenant->companyId();
                }
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
