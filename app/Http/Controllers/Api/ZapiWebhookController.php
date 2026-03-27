<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Zapi\ZapiWebhookService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ZapiWebhookController extends Controller
{
    public function __invoke(Request $request, ZapiWebhookService $service)
    {
        $expectedToken = (string) config('services.zapi.webhook_token');
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

        $payload = $request->all();
        $event = $service->ingest($payload);

        return response()->json([
            'message' => 'Webhook processed.',
            'event_id' => $event->id,
        ]);
    }
}
