<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Zapi\ZapiWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

class AdminEndpointsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (! $this->authorized($request)) {
            return response()->json(['message' => 'Unauthorized.'], Response::HTTP_UNAUTHORIZED);
        }

        $routes = collect(Route::getRoutes())
            ->map(fn ($route): array => [
                'method' => implode('|', $route->methods()),
                'uri' => '/'.$route->uri(),
                'name' => $route->getName() ?: '-',
                'middleware' => $route->gatherMiddleware(),
            ])
            ->sortBy(['uri', 'method'])
            ->values();

        return response()->json([
            'data' => $routes,
            'count' => $routes->count(),
        ]);
    }

    public function testWebhook(Request $request, ZapiWebhookService $service): JsonResponse
    {
        if (! $this->authorized($request)) {
            return response()->json(['message' => 'Unauthorized.'], Response::HTTP_UNAUTHORIZED);
        }

        $payload = $request->input('payload');

        if (! is_array($payload)) {
            $payload = $this->defaultPayload();
        }

        $event = $service->ingest($payload);

        return response()->json([
            'message' => 'Webhook de teste processado com sucesso.',
            'event_id' => $event->id,
        ]);
    }

    private function authorized(Request $request): bool
    {
        $token = trim((string) config('services.admin.api_token'));

        if ($token === '') {
            return true;
        }

        $provided = (string) $request->header('X-Admin-Token', '');

        return hash_equals($token, $provided);
    }

    private function defaultPayload(): array
    {
        return [
            'event' => 'message.received',
            'messageId' => 'evt_'.str()->uuid(),
            'phone' => '5511999999999',
            'senderName' => 'Cliente Teste',
            'text' => [
                'message' => 'Quero um combo promocional',
            ],
            'order' => [
                'id' => 'ord_'.now()->format('YmdHis'),
                'code' => 'PED-'.now()->format('His'),
                'status' => 'new',
                'total' => 49.90,
                'customer' => [
                    'name' => 'Cliente Teste',
                    'phone' => '5511999999999',
                    'address' => 'Rua Exemplo, 100',
                ],
            ],
        ];
    }
}
