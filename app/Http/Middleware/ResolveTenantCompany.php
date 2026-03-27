<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenantCompany
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var TenantContext $tenant */
        $tenant = app(TenantContext::class);
        $company = $this->resolveCompany($request);

        $tenant->setCompanyId($company?->id);

        return $next($request);
    }

    private function resolveCompany(Request $request): ?Company
    {
        $companyHeader = (int) $request->header('X-Company-Id', 0);

        if ($companyHeader > 0) {
            return Company::query()->find($companyHeader);
        }

        $token = trim((string) $request->header('X-Company-Token', ''));

        if ($token !== '') {
            return Company::query()->where('api_token', $token)->first();
        }

        $instanceId = (string) data_get($request->all(), 'instanceId', data_get($request->all(), 'instance.id', ''));

        if ($instanceId !== '') {
            return Company::query()->where('zapi_instance_id', $instanceId)->first();
        }

        $user = $request->user();

        if ($user !== null && $user->company_id !== null) {
            return Company::query()->find($user->company_id);
        }

        return null;
    }
}
