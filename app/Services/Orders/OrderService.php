<?php

namespace App\Services\Orders;

use App\Domain\Orders\OrderStateMachine;
use App\Models\Order;
use App\Services\Whatsapp\WhatsAppOrchestrator;
use App\Support\Audit\AuditLogger;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class OrderService
{
    // Active status groups that map to the front-end sub-tabs.
    public const STATUS_TAB_NEW  = 'new';
    public const STATUS_TAB_PREP = 'preparing';       // "Em Preparo" includes confirmed
    public const STATUS_TAB_ROAD = 'out_for_delivery'; // "Na Rota"

    /** Statuses considered "active" (visible in Ativos tab). */
    public const ACTIVE_STATUSES = ['new', 'confirmed', 'preparing', 'out_for_delivery'];

    /** Statuses considered "history" (visible in Histórico tab). */
    public const HISTORY_STATUSES = ['delivered', 'cancelled'];

    /** Minutes added to estimated_ready_at when an order is accepted. Default 45 min. */
    private const DEFAULT_PREP_MINUTES = 45;

    public function __construct(
        private readonly OrderStateMachine $stateMachine,
        private readonly WhatsAppOrchestrator $orchestrator,
        private readonly AuditLogger $auditLogger,
    ) {}

    // ── Queries ──────────────────────────────────────────────────────────────

    /**
     * Paginated order list.
     *
     * Query params:
     *   tab      = active (default) | history
     *   status   = new|confirmed|preparing|out_for_delivery|delivered|cancelled
     *   search   = customer name/phone partial match
     *   per_page = default 20
     */
    public function list(Request $request): LengthAwarePaginator
    {
        $tab = $request->query('tab', 'active');

        return Order::query()
            ->with([
                'items' => fn ($q) => $q->with('product:id,image_path'),
                'delivery:id,address,status',
            ])
            ->when($tab === 'history', fn (Builder $q) => $q->whereIn('status', self::HISTORY_STATUSES))
            ->when($tab !== 'history', fn (Builder $q) => $q->whereIn('status', self::ACTIVE_STATUSES))
            ->when(
                $request->filled('status'),
                fn (Builder $q) => $q->where('status', $request->query('status'))
            )
            ->when(
                $request->filled('search'),
                fn (Builder $q) => $q->where(function (Builder $inner) use ($request): void {
                    $term = '%'.$request->string('search')->toString().'%';
                    $inner->where('customer_name', 'like', $term)
                          ->orWhere('customer_phone', 'like', $term)
                          ->orWhere('code', 'like', $term);
                })
            )
            ->orderByDesc('ordered_at')
            ->orderByDesc('id')
            ->paginate((int) $request->query('per_page', 20));
    }

    /**
     * Summary counts shown in the sub-tab headers.
     *
     * Returns:
     *   new             – orders awaiting acceptance
     *   confirmed       – accepted but prep not started
     *   preparing       – kitchen working on it
     *   out_for_delivery – on the road
     */
    public function summary(): array
    {
        $counts = Order::query()
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();

        return [
            'new'              => (int) ($counts['new'] ?? 0),
            'confirmed'        => (int) ($counts['confirmed'] ?? 0),
            'preparing'        => (int) ($counts['preparing'] ?? 0),
            'out_for_delivery' => (int) ($counts['out_for_delivery'] ?? 0),
        ];
    }

    /**
     * Single order detail with all relations.
     */
    public function detail(Order $order): Order
    {
        return $order->load([
            'items' => fn ($q) => $q->with('product:id,image_path'),
            'delivery:id,address,status',
            'store:id,name',
        ]);
    }

    // ── Mutations ────────────────────────────────────────────────────────────

    /**
     * Accept a new order → confirmed, set countdown timer.
     *
     * @param  int  $prepMinutes  How many minutes to give for preparation.
     */
    public function accept(Order $order, int $prepMinutes, Request $request): Order
    {
        abort_unless(
            $this->stateMachine->transition($order, 'confirmed'),
            422,
            'Este pedido não pode ser aceito no estado atual.'
        );

        $order->estimated_ready_at = now()->addMinutes(max(1, $prepMinutes ?: self::DEFAULT_PREP_MINUTES));
        $order->save();

        $this->auditLogger->log('order.accepted', [
            'entity_type' => Order::class,
            'entity_id'   => $order->id,
            'changes'     => ['status' => 'confirmed', 'estimated_ready_at' => $order->estimated_ready_at],
        ], $request);

        $this->orchestrator->queueStatusNotification($order);

        return $order->refresh()->load('items.product:id,image_path');
    }

    /**
     * Reject a new order → cancelled.
     */
    public function reject(Order $order, ?string $reason, Request $request): Order
    {
        abort_unless(
            $this->stateMachine->transition($order, 'cancelled'),
            422,
            'Este pedido não pode ser rejeitado no estado atual.'
        );

        $order->rejection_reason = $reason;
        $order->save();

        $this->auditLogger->log('order.rejected', [
            'entity_type' => Order::class,
            'entity_id'   => $order->id,
            'changes'     => ['status' => 'cancelled', 'rejection_reason' => $reason],
        ], $request);

        $this->orchestrator->queueStatusNotification($order);

        return $order->refresh()->load('items.product:id,image_path');
    }

    /**
     * Advance order along the happy path:
     *   confirmed → preparing → out_for_delivery → delivered
     */
    public function advance(Order $order, Request $request): Order
    {
        $nextState = match ($order->status) {
            'confirmed'        => 'preparing',
            'preparing'        => 'out_for_delivery',
            'out_for_delivery' => 'delivered',
            default            => null,
        };

        abort_if($nextState === null, 422, 'Não há próximo estado para este pedido.');

        abort_unless(
            $this->stateMachine->transition($order, $nextState),
            422,
            'Transição de estado inválida.'
        );

        $this->auditLogger->log('order.advanced', [
            'entity_type' => Order::class,
            'entity_id'   => $order->id,
            'changes'     => ['status' => $nextState],
        ], $request);

        $this->orchestrator->queueStatusNotification($order);

        return $order->refresh()->load('items.product:id,image_path');
    }
}
