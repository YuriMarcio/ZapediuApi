<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Plan;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CompanyController extends Controller
{
    public function me(TenantContext $tenant): JsonResponse
    {
        $company = Company::query()->with(['plan', 'stores' => fn ($q) => $q->orderBy('id')->limit(1)])->findOrFail($tenant->companyId());

        $data = $company->toArray();
        unset($data['stores']);

        /** @var \App\Models\Store|null $store */
        $store = $company->stores->first();
        $data['logo_path'] = $store?->logo_path;
        $data['logo_url']  = $store?->logo_url;

        if ($company->plan) {
            $data['plan'] = [
                'slug'        => $company->plan->slug,
                'name'        => $company->plan->name,
                'tagline'     => $company->plan->tagline,
                'pitch'       => $company->plan->pitch,
                'fee_percent' => $company->plan->fee_percent,
                'fee_fixed'   => $company->plan->fee_fixed,
                'features'    => $company->plan->features,
            ];
        } else {
            $data['plan'] = null;
        }

        return response()->json($data);
    }

    public function update(Request $request, TenantContext $tenant): JsonResponse
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

       

        return response()->json($company);
    }

    public function switchPlan(Request $request, TenantContext $tenant): JsonResponse
    {
        $data = $request->validate([
            'plan_slug' => ['required', 'string', Rule::exists('plans', 'slug')->where('is_active', true)],
        ]);

        $plan    = Plan::query()->where('slug', $data['plan_slug'])->firstOrFail();
        $company = Company::query()->findOrFail($tenant->companyId());
        $company->plan_id = $plan->id;
        $company->save();

        

        return response()->json([
            'message' => 'Plano atualizado com sucesso.',
            'plan'    => [
                'slug'        => $plan->slug,
                'name'        => $plan->name,
                'fee_percent' => $plan->fee_percent,
                'fee_fixed'   => $plan->fee_fixed,
            ],
        ]);
    }
}
