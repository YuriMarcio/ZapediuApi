<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
    public function me(TenantContext $tenant): JsonResponse
    {
        $company = Company::query()->findOrFail($tenant->companyId());

        return response()->json($company);
    }

    public function update(Request $request, TenantContext $tenant, AuditLogger $auditLogger): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:160'],
            'segment' => ['sometimes', 'string', 'max:80'],
            'shipping_rules' => ['sometimes', 'array'],
            'business_hours' => ['sometimes', 'array'],
            'settings' => ['sometimes', 'array'],
            'zapi_instance_id' => ['sometimes', 'nullable', 'string', 'max:120'],
            'zapi_instance_token' => ['sometimes', 'nullable', 'string', 'max:190'],
            'zapi_client_token' => ['sometimes', 'nullable', 'string', 'max:190'],
            'zapi_webhook_token' => ['sometimes', 'nullable', 'string', 'max:190'],
        ]);

        $company = Company::query()->findOrFail($tenant->companyId());
        $company->fill($data);
        $company->save();

        $auditLogger->log('company.updated', [
            'entity_type' => Company::class,
            'entity_id' => $company->id,
            'changes' => $data,
        ], $request);

        return response()->json($company);
    }
}
