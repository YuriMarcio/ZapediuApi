<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Payment\PlatformFeeCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MasterOrderController extends Controller
{
    public function __construct(private readonly PlatformFeeCalculator $platformFee)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $orders = Order::query()
            ->withoutGlobalScopes()
            ->with([
                'store:id,name,company_id',
                'company:id,name,operation_id,plan_id,whatsapp',
                'company.operation:id,name',
                'company.operation.whatsappSession:id,operation_id,phone_number',
                'company.plan:id,fee_percent,fee_fixed',
                'user:id,name',
                'user.primaryAddress',
                'courier:id,name',
            ])
            ->orderByDesc('ordered_at')
            ->limit(300)
            ->get();

        return response()->json(['data' => $orders->map(fn (Order $order) => $this->present($order))->values()]);
    }

    private function present(Order $order): array
    {
        $total = (float) $order->total;
        // Mesmo cálculo enviado como application_fee ao Mercado Pago, pra o painel não
        // divergir do que foi efetivamente retido na cobrança.
        $platformRevenue = $this->platformFee->forOrder($order);

        return [
            'id' => (string) $order->id,
            'code' => $order->code,
            'date' => ($order->ordered_at ?? $order->created_at)?->toIso8601String(),
            'operation_id' => $order->company?->operation_id,
            'operation_name' => $order->company?->operation?->name,
            'store_name' => $order->store?->name,
            'customer_name' => $order->user?->name ?? 'Cliente não identificado',
            'neighborhood' => $order->user?->primaryAddress?->district,
            'total' => $total,
            'payment_method' => $order->payment_method,
            'status' => $this->mapStatus($order),
            'courier_name' => $order->courier?->name,
            'whatsapp_phone' => $order->company?->operation?->whatsappSession?->phone_number ?? $order->company?->whatsapp,
            'platform_revenue' => $platformRevenue,
        ];
    }

    /**
     * Colapsa o enum interno (pending/accepted/preparing/preparToDelivery/delivering/done/
     * cancelled) + payment_status pro vocabulário de 5 estados que a tela do master usa
     * (pago/concluido/em_andamento/cancelado/reembolsado). Não existe "reembolsado" real
     * hoje no backend (payment_status só assume pending/paid/failed), então esse estado
     * nunca é emitido — fica reservado pro dia que o reembolso via MP for implementado.
     */
    private function mapStatus(Order $order): string
    {
        $status = $order->statusValue();

        if ($status === 'cancelled') {
            return 'cancelado';
        }

        if ($status === 'done') {
            return 'concluido';
        }

        if ($order->payment_status === 'paid') {
            return 'pago';
        }

        return 'em_andamento';
    }
}
