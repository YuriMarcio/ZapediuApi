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
        logger()->info('Acessando checkout público', [
            'order_code' => $order->code,
            'query_token' => $request->query('token'),
            'input_token' => $request->input('token'),
            'header_token' => $request->header('X-Checkout-Token'),
        ]);

        if ($response = $this->ensureAuthorized($order, $request)) {
            return $response;
        }

        $order->loadMissing(['store', 'user']);

        $user = $order->user;

        Logger()->info('Dados do usuário associado ao pedido', [
            $order
        ]);
        $wallet = \App\Models\Wallet::where('company_id', $order->company_id)->first();

        Logger()->info('Dados da carteira da empresa', [
            'wallet' => $wallet ? $wallet->toArray() : null
        ]);

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
                'items' => collect(data_get($order->raw_payload, 'cart.items', []))->map(function ($item) {
                    // Vamos no banco buscar apenas esse produto
                    $product = \App\Models\Product::find(data_get($item, 'product_id'));

                    return [
                        'id' => data_get($item, 'product_id'),
                        'name' => data_get($item, 'product_name'),
                        'quantity' => data_get($item, 'quantity'),
                        'price' => (float) data_get($item, 'base_price'),

                        // AQUI: Pegamos a imagem do banco, ou retornamos null se der ruim
                        'image' => $product ? $product->image_path : null,

                        'options' => data_get($item, 'variation_name'),
                    ];
                })->values()->all()
            ],
            'store' => [
                                                'logo' => $order->store?->logo_url,
                                                'name' => $order->store?->name,
                                                'slug' => $order->store?->slug,
                                            ],
            'customer' => [
                'name' => data_get($order->raw_payload, 'customer.name', $order->user?->name),
                'email' => data_get($order->raw_payload, 'customer.email', $order->user?->email),
                'whatsapp' => data_get($order->raw_payload, 'customer.phone', $order->user?->phone),
                // Puxa a string do endereço direto como o bot salvou:
                'address' => data_get($order->raw_payload, 'customer.address'),
            ],
            'checkout' => [
                'can_pay' => ! in_array($order->payment_status, ['paid', 'approved'], true),
                'source' => data_get($order->raw_payload, 'checkout.source', 'web'),
                'delivery_mode' => data_get($order->raw_payload, 'checkout.delivery_mode', 'store'),
                'mp_public_key' => $wallet ? $wallet->mp_public_key : env('VITE_MP_PUBLIC_KEY'),
            ],
            'payment_methods' => ['pix', 'card'],
        ], 200, ['Content-Type' => 'application/json']);
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

        return response()->json($payload, 201, ['Content-Type' => 'application/json']);
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
