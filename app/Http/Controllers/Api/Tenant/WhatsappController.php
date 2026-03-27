<?php

namespace App\Http\Controllers\Api\Tenant;

use App\DataTransferObjects\Whatsapp\CarouselProductCardData;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Services\Whatsapp\WhatsAppOrchestrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WhatsappController extends Controller
{
    public function sendCarousel(Request $request, WhatsAppOrchestrator $orchestrator): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:50'],
            'message' => ['required', 'string', 'max:1000'],
            'product_ids' => ['required', 'array', 'min:1', 'max:10'],
            'product_ids.*' => ['required', 'integer', 'exists:products,id'],
        ]);

        $products = Product::query()->whereIn('id', $validated['product_ids'])->get();

        $cards = $products->map(function (Product $product): CarouselProductCardData {
            $buttonPayload = 'buy_'.($product->id).'_'.strtolower(str_replace(' ', '_', $product->name));

            return new CarouselProductCardData(
                title: $product->name,
                description: 'A partir de R$ '.number_format((float) $product->price, 2, ',', '.'),
                imageUrl: (string) ($product->image_url ?? url('/images/placeholder-product.png')),
                buttonPayload: $buttonPayload,
                buttonLabel: 'Comprar',
            );
        })->values()->all();

        $companyId = (int) ($request->user()?->company_id ?? 0);

        $orchestrator->queueCarousel($companyId, (string) $validated['phone'], (string) $validated['message'], $cards);

        return response()->json([
            'message' => 'Envio do carrossel enfileirado com sucesso.',
            'queued_cards' => count($cards),
        ]);
    }

    public function notifyOrderStatus(Request $request, Order $order, WhatsAppOrchestrator $orchestrator): JsonResponse
    {
        $validated = $request->validate([
            'template' => ['required', 'string', 'max:240'],
        ]);

        if (! is_string($order->customer_phone) || $order->customer_phone === '') {
            return response()->json([
                'message' => 'Pedido sem telefone do cliente.',
            ], 422);
        }

        $companyId = (int) ($request->user()?->company_id ?? 0);

        $orchestrator->queueStatusNotification(
            $companyId,
            $order->customer_phone,
            (string) $validated['template'],
            [
                'order_code' => $order->code,
                'status' => $order->status,
                'total' => number_format((float) $order->total, 2, ',', '.'),
            ]
        );

        return response()->json(['message' => 'Notificacao de status enfileirada.']);
    }
}
