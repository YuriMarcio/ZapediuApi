<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\Whatsapp\ProcessZapiWebhookJob;
use App\Services\Whatsapp\FlowBridgeInboundPayloadMapper;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Recebe os eventos que o FlowBridge repassa via callbackUrl (POST sem assinatura — o token
 * na própria URL é a autenticação). Só processa MessageReceived; os demais tipos de evento
 * (MessageDelivered, MessageRead, InstanceConnected, ConnectionLost...) só são logados por
 * enquanto. Reaproveita ProcessZapiWebhookJob traduzindo o payload primeiro — ver
 * FlowBridgeInboundPayloadMapper.
 */
class FlowBridgeWebhookController extends Controller
{
    public function __invoke(Request $request, string $token, TenantContext $tenant)
    {
        $expectedToken = (string) config('services.flowbridge.webhook_secret');

        if ($expectedToken === '' || ! hash_equals($expectedToken, $token)) {
            return response()->json(['message' => 'Unauthorized webhook token.'], Response::HTTP_UNAUTHORIZED);
        }

        $event = $request->all();

        Log::info('Recebido evento FlowBridge', ['event' => $event]);

        if (($event['type'] ?? null) !== 'MessageReceived') {
            return response()->json(['message' => 'Event ignored.', 'type' => $event['type'] ?? null]);
        }

        $payload = FlowBridgeInboundPayloadMapper::toZapiShape($event);

        if ($payload === null) {
            return response()->json(['message' => 'Unsupported message content.']);
        }

        // FLOWBRIDGE_INSTANCE_ID ainda não tem coluna própria em companies — enquanto só
        // existir uma instância, processa em modo single-tenant (companyId nulo), igual ao
        // ZapiWebhookController quando não acha company pelo instanceId.
        $tenant->setCompanyId(null);
        ProcessZapiWebhookJob::dispatch(null, $payload);

        return response()->json(['message' => 'Webhook accepted for async processing.']);
    }
}
