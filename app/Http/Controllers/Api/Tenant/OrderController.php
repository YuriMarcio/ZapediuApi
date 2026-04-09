<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Orders\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(private readonly OrderService $orderService) {}

    /**
     * GET /tenant/orders
     *
     * Query params:
     *   tab      = active (default) | history
        *   status   = pending|accepted|preparing|delivering|done|cancelled
     *   search   = customer name/phone/code
     *   per_page = default 20
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->orderService->list($request));
    }

    /**
     * GET /tenant/orders/summary
        * Returns live counts used by the board headers.
     */
    public function summary(): JsonResponse
    {
        return response()->json($this->orderService->summary());
    }

    /**
     * GET /tenant/orders/{order}
        * Full detail in the frontend order-contract shape.
     */
    public function show(Order $order): JsonResponse
    {
        return response()->json($this->orderService->detail($order));
    }

    /**
     * POST /tenant/orders/{order}/accept
     *
     * Body: { "prep_minutes": 45 }   (optional, default 45)
     *
    * Transitions: pending → accepted
     * Side-effects: sets estimated_ready_at, queues WhatsApp notification.
     */
    public function accept(Request $request, Order $order): JsonResponse
    {
        $request->validate([
            'prep_minutes' => ['nullable', 'integer', 'min:1', 'max:300'],
        ]);

        $order = $this->orderService->accept(
            $order,
            (int) $request->input('prep_minutes', 45),
            $request
        );

        return response()->json($order);
    }

    /**
     * POST /tenant/orders/{order}/reject
     *
     * Body: { "reason": "Fora do horário de entrega" }   (optional)
     *
    * Transitions: pending → cancelled
     * Side-effects: stores rejection_reason, queues WhatsApp notification.
     */
    public function reject(Request $request, Order $order): JsonResponse
    {
        $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $order = $this->orderService->reject(
            $order,
            $request->input('reason'),
            $request
        );

        return response()->json($order);
    }

    /**
     * POST /tenant/orders/{order}/advance
     *
     * Advances along the happy path:
    *   accepted → preparing → delivering → done
     *
     * Side-effects: queues WhatsApp notification for every transition.
     */
    public function advance(Request $request, Order $order): JsonResponse
    {
        $order = $this->orderService->advance($order, $request);

        return response()->json($order);
    }
}
