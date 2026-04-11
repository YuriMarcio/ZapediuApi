<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\PublicCheckoutRequest;
use App\Models\Order;
use App\Services\Payments\CheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicCheckoutController extends Controller
{
    public function show(Request $request, Order $order): JsonResponse
    {
        if ($response = $this->ensureAuthorized($order, $request)) {
            return $response;
        }

        $order->loadMissing(['store', 'user']);

        return response()->json([
            'order' => [
                'code' => $order->code,
                'status' => $order->status,
                'payment_status' => $order->payment_status,
                'payment_method' => $order->payment_method,
                'subtotal' => (float) $order->subtotal,
                'delivery_fee' => (float) $order->delivery_fee,
                'discount' => (float) $order->discount,
                'total' => (float) $order->total,
                'notes' => $order->notes,
                'ordered_at' => optional($order->ordered_at)?->toIso8601String(),
                'items' => $this->serializeItems($order),
            ],
            'store' => [
                'logo' => $order->store?->logo_url,
                'name' => $order->store?->name,
                'slug' => $order->store?->slug,
            ],
            'customer' => [
                'name' => $order->user?->name,
                'email' => $order->user?->email,
                'number' => $order->user?->phone_number,
            ],
            'checkout' => [
                'can_pay' => ! in_array($order->payment_status, ['paid', 'approved'], true),
                'source' => data_get($order->raw_payload, 'checkout.source', 'web'),
                'delivery_mode' => data_get($order->raw_payload, 'checkout.delivery_mode', 'store'),
            ],
            'payment_methods' => ['pix', 'card'],
        ]);
    }

    public function store(Order $order, PublicCheckoutRequest $request, CheckoutService $checkout): JsonResponse
    {
        if ($response = $this->ensureAuthorized($order, $request)) {
            return $response;
        }

        if (in_array($order->payment_status, ['paid', 'approved'], true)) {
            return response()->json([
                'message' => 'Este pedido ja possui pagamento confirmado.',
                'order_code' => $order->code,
            ], 409);
        }

        $payload = $checkout->createForOrder($order, $request->validated(), $request);

        return response()->json($payload, 201);
    }

    private function serializeItems(Order $order): array
    {
        $items = data_get($order->raw_payload, 'cart.items', data_get($order->raw_payload, 'order.items', []));

        if (! is_array($items)) {
            return [];
        }

        return collect($items)->map(function (array $item): array {
            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $basePrice = (float) ($item['base_price'] ?? data_get($item, 'unit_price', data_get($item, 'price', 0)));
            $additionalPrice = (float) ($item['additional_price'] ?? 0);

            return [
                'product_id' => isset($item['product_id']) ? (int) $item['product_id'] : null,
                'name' => (string) ($item['product_name'] ?? data_get($item, 'name', 'Produto')),
                'variation_name' => data_get($item, 'variation_name'),
                'quantity' => $quantity,
                'unit_price' => round($basePrice + $additionalPrice, 2),
                'line_total' => round(($basePrice + $additionalPrice) * $quantity, 2),
                'observation' => data_get($item, 'observation'),
            ];
        })->values()->all();
    }

    private function ensureAuthorized(Order $order, Request $request): ?JsonResponse
    {
        $expectedToken = (string) data_get($order->raw_payload, 'checkout.public_token', '');
        $providedToken = trim((string) ($request->query('token')
            ?? $request->input('token')
            ?? $request->header('X-Checkout-Token')
            ?? ''));

        if ($expectedToken === '') {
            return response()->json([
                'message' => 'Checkout publico indisponivel para este pedido.',
                'order_code' => $order->code,
            ], 403);
        }

        if ($providedToken === '' || ! hash_equals($expectedToken, $providedToken)) {
            return response()->json([
                'message' => 'Token de checkout invalido.',
                'order_code' => $order->code,
            ], 403);
        }

        return null;
    }
}