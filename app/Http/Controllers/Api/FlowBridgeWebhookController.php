<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\Whatsapp\ProcessZapiWebhookJob;
use App\Models\Company;
use App\Services\Whatsapp\FlowBridgeInboundPayloadMapper;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Recebe os eventos que o FlowBridge repassa via callbackUrl. Só processa MessageReceived; os
 * demais tipos de evento (MessageDelivered, MessageRead, InstanceConnected, ConnectionLost...)
 * só são logados por enquanto. Reaproveita ProcessZapiWebhookJob traduzindo o payload primeiro
 * — ver FlowBridgeInboundPayloadMapper.
 */
class FlowBridgeWebhookController extends Controller
{
    public function __invoke(Request $request, string $token, TenantContext $tenant)
    {
        $event = $request->all();
        $company = $this->resolveCompany($event);
        $expectedToken = $company?->flowbridge_webhook_secret ?: (string) config('services.flowbridge.webhook_secret');

        if ($expectedToken === '' || ! hash_equals($expectedToken, $token)) {
            return response()->json(['message' => 'Unauthorized webhook token.'], Response::HTTP_UNAUTHORIZED);
        }

        Log::info('Recebido evento FlowBridge', ['event' => $event, 'company_id' => $company?->id]);

        if (($event['type'] ?? null) !== 'MessageReceived') {
            return response()->json(['message' => 'Event ignored.', 'type' => $event['type'] ?? null]);
        }

        $payload = FlowBridgeInboundPayloadMapper::toZapiShape($event);

        if ($payload === null) {
            return response()->json(['message' => 'Unsupported message content.']);
        }

        $tenant->setCompanyId($company?->id);
        ProcessZapiWebhookJob::dispatch($company?->id, $payload);

        return response()->json([
            'message' => 'Webhook accepted for async processing.',
            'company_id' => $company?->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function resolveCompany(array $event): ?Company
    {
        $instanceId = (string) ($event['instanceId'] ?? '');

        if ($instanceId === '') {
            return null;
        }

        return Company::query()->where('flowbridge_instance_id', $instanceId)->first();
    }
}
