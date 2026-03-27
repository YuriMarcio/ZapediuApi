<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Zapi\ZapiClient;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ZapiSendTextController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, ZapiClient $client)
    {
        $expectedToken = (string) config('services.zapi.webhook_token');

        if ($expectedToken !== '') {
            $providedToken = (string) $request->header('X-Webhook-Token');

            if (! hash_equals($expectedToken, $providedToken)) {
                return response()->json([
                    'message' => 'Unauthorized token.',
                ], Response::HTTP_UNAUTHORIZED);
            }
        }

        $validated = $request->validate([
            'phone' => ['required', 'string', 'min:8'],
            'message' => ['required', 'string', 'min:1', 'max:4096'],
        ]);

        $result = $client->sendText(
            (string) $validated['phone'],
            (string) $validated['message']
        );

        return response()->json([
            'message' => 'Message sent.',
            'zapi_response' => $result,
        ]);
    }
}
