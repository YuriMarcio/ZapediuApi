<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\ZapiWebhookRequest;
use App\Jobs\Whatsapp\ProcessZapiWebhookJob;
use App\Models\Company;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Symfony\Component\HttpFoundation\Response;

class ZapiWebhookController extends Controller
{
    public function __invoke(ZapiWebhookRequest $request, TenantContext $tenant)
    {
        $payload = $request->validated();
        $company = $this->resolveCompany($payload);
        $expectedToken = $company?->zapi_webhook_token ?: (string) config('services.zapi.webhook_token');
        $mustValidateToken = $expectedToken !== '' && $expectedToken !== 'change-me';

        if ($mustValidateToken) {
            $providedToken = (string) $request->header('X-Webhook-Token');

            if ($providedToken === '') {
                $providedToken = (string) $request->query('token', '');
            }

            if (! hash_equals($expectedToken, $providedToken)) {
                return response()->json([
                    'message' => 'Unauthorized webhook token.',
                ], Response::HTTP_UNAUTHORIZED);
            }
        }

        $tenant->setCompanyId($company?->id);
        ProcessZapiWebhookJob::dispatch($company?->id, $payload);

        return response()->json([
            'message' => 'Webhook accepted for async processing.',
            'company_id' => $company?->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveCompany(array $payload): ?Company
    {
        $instanceId = (string) data_get($payload, 'instanceId', data_get($payload, 'instance.id', ''));

        if ($instanceId === '') {
            return null;
        }

        return Company::query()->where('zapi_instance_id', $instanceId)->first();
    }
}
