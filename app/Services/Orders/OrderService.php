<?php

namespace App\Services\Orders;

use App\Domain\Orders\OrderStateMachine;
use App\Models\Order;
use App\Models\Product;
use App\Services\Whatsapp\WhatsAppOrchestrator;
use App\Support\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class OrderService
{
    // Active status groups that map to the front-end sub-tabs.
    public const STATUS_TAB_NEW  = 'pending';
    public const STATUS_TAB_PREP = 'preparing';       // "Em Preparo" includes confirmed
    public const STATUS_TAB_ROAD = 'delivering'; // "Na Rota"

    /** Statuses considered "active" (visible in Ativos tab). */
    public const ACTIVE_STATUSES = ['pending', 'accepted', 'preparing', 'delivering'];

    /** Statuses considered "history" (visible in Histórico tab). */
    public const HISTORY_STATUSES = ['done', 'cancelled'];

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
    *   status   = pending|accepted|preparing|delivering|done|cancelled
     *   search   = customer name/phone partial match
     *   per_page = default 20
     */
    public function list(Request $request): array
    {
        $tab = $request->query('tab', 'active');

        $orders = Order::query()
            ->with([
                'user:id,name,email,phone',
                'user.primaryPhone:id,user_id,phone,label,is_primary',
                'user.primaryAddress:id,user_id,street,number,district,complement,city,state,zip_code,formatted,notes,is_primary',
                'delivery:id,address,status',
                'store:id,name',
            ])
            ->when($tab === 'history', fn (Builder $q) => $q->whereIn('status', self::HISTORY_STATUSES))
            ->when($tab !== 'history', fn (Builder $q) => $q->whereIn('status', self::ACTIVE_STATUSES)->where('payment_status', 'paid'))
            ->when(
                $request->filled('status'),
                fn (Builder $q) => $q->where('status', $request->query('status'))
            )
            ->when(
                $request->filled('search'),
                fn (Builder $q) => $q->where(function (Builder $inner) use ($request): void {
                    $term = '%'.$request->string('search')->toString().'%';
                    $inner->where('code', 'like', $term)
                          ->orWhereHas('user', function (Builder $userQuery) use ($term): void {
                              $userQuery->where('name', 'like', $term)
                                  ->orWhere('phone', 'like', $term)
                                  ->orWhere('email', 'like', $term);
                            })->orWhereHas('user.phones', function (Builder $phoneQuery) use ($term): void {
                                $phoneQuery->where('phone', 'like', $term);
                          });
                })
            )
            ->orderByDesc('ordered_at')
            ->orderByDesc('id')
            ->paginate((int) $request->query('per_page', 20));

        $collection = $orders->getCollection();
        $this->attachProducts($collection);

        return [
            'data' => $collection->map(fn (Order $order) => $this->transformListOrder($order))->values()->all(),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ],
        ];
    }

    /**
     * Summary counts shown in the sub-tab headers.
     *
     * Returns:
    *   pending    – orders awaiting acceptance
    *   accepted   – accepted but prep not started
    *   preparing  – kitchen working on it
    *   delivering – on the road
     */
    public function summary(): array
    {
        $activeCounts = Order::query()
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->where('payment_status', 'paid')
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();

        $historyCounts = Order::query()
            ->whereIn('status', self::HISTORY_STATUSES)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();

        return [
            'pending' => (int) ($activeCounts['pending'] ?? 0),
            'accepted' => (int) ($activeCounts['accepted'] ?? 0),
            'preparing' => (int) ($activeCounts['preparing'] ?? 0),
            'delivering' => (int) ($activeCounts['delivering'] ?? 0),
            'done' => (int) ($historyCounts['done'] ?? 0),
            'cancelled' => (int) ($historyCounts['cancelled'] ?? 0),
        ];
    }

    /**
     * Single order detail with all relations.
     */
    public function detail(Order $order): array
    {
        $order->load([
            'user:id,name,email,phone',
            'user.primaryPhone:id,user_id,phone,label,is_primary',
            'user.primaryAddress:id,user_id,street,number,district,complement,city,state,zip_code,formatted,notes,is_primary',
            'delivery:id,address,status',
            'store:id,name',
        ]);

        $this->attachProducts(new Collection([$order]));

        return $this->transformFullOrder($order);
    }

    // ── Mutations ────────────────────────────────────────────────────────────

    /**
    * Accept a pending order → accepted, set countdown timer.
     *
     * @param  int  $prepMinutes  How many minutes to give for preparation.
     */
    public function accept(Order $order, int $prepMinutes, Request $request): array
    {
        abort_unless(
            $this->stateMachine->transition($order, 'accepted'),
            422,
            'Este pedido não pode ser aceito no estado atual.'
        );

        $order->estimated_ready_at = now()->addMinutes(max(1, $prepMinutes ?: self::DEFAULT_PREP_MINUTES));
        $order->save();

        $this->auditLogger->log('order.accepted', [
            'entity_type' => Order::class,
            'entity_id'   => $order->id,
            'changes'     => ['status' => 'accepted', 'estimated_ready_at' => $order->estimated_ready_at],
        ], $request);

        $this->queueStatusNotification($order);

        $order = $order->refresh()->load(['user:id,name,email,phone', 'delivery:id,address,status', 'store:id,name']);
        $this->attachProducts(new Collection([$order]));

        return $this->transformFullOrder($order);
    }

    /**
    * Reject a pending order → cancelled.
     */
    public function reject(Order $order, ?string $reason, Request $request): array
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

        $this->queueStatusNotification($order);

        $order = $order->refresh()->load(['user:id,name,email,phone', 'delivery:id,address,status', 'store:id,name']);
        $this->attachProducts(new Collection([$order]));

        return $this->transformFullOrder($order);
    }

    /**
    * Advance order along the happy path:
    *   accepted → preparing → delivering → done
     */
    public function advance(Order $order, Request $request): array
    {
        $nextState = match ($order->status) {
            'accepted' => 'preparing',
            'preparing' => 'delivering',
            'delivering' => 'done',
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

        $this->queueStatusNotification($order);

        $order = $order->refresh()->load(['user:id,name,email,phone', 'delivery:id,address,status', 'store:id,name']);
        $this->attachProducts(new Collection([$order]));

        return $this->transformFullOrder($order);
    }

    private function attachProducts(Collection $orders): void
    {
        $productIds = $orders
            ->pluck('product_ids')
            ->filter(fn ($ids) => is_array($ids) && $ids !== [])
            ->flatten()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $products = Product::query()
            ->whereIn('id', $productIds)
            ->get(['id', 'name', 'price', 'image_path', 'category_id'])
            ->keyBy('id');

        foreach ($orders as $order) {
            $orderedProducts = collect($order->product_ids ?? [])
                ->map(fn ($productId) => $products->get((int) $productId))
                ->filter()
                ->values();

            $order->setRelation('products', $orderedProducts);
        }
    }

    private function transformListOrder(Order $order): array
    {
        $customer = $this->transformCustomer($order);
        $delivery = $this->transformDelivery($order);
        $payment = $this->transformPayment($order);
        $pricing = $this->transformPricing($order);

        return [
            'id' => $order->id,
            'code' => $order->code,
            'status' => $order->status,
            'created_at' => optional($order->created_at)?->toIso8601String(),
            'updated_at' => optional($order->updated_at)?->toIso8601String(),
            'customer' => Arr::only($customer, ['name', 'phone']),
            'delivery' => [
                'address' => [
                    'formatted' => data_get($delivery, 'address.formatted'),
                ],
            ],
            'payment' => [
                'label' => $payment['label'],
            ],
            'pricing' => [
                'total' => $pricing['total'],
            ],
            'items' => collect($this->transformItems($order))
                ->map(fn (array $item) => Arr::only($item, ['id', 'name', 'quantity', 'unit_price', 'image_url']))
                ->values()
                ->all(),
        ];
    }

    private function transformFullOrder(Order $order): array
    {
        return [
            'id' => $order->id,
            'code' => $order->code,
            'status' => $order->status,
            'created_at' => optional($order->created_at)?->toIso8601String(),
            'updated_at' => optional($order->updated_at)?->toIso8601String(),
            'customer' => $this->transformCustomer($order),
            'delivery' => $this->transformDelivery($order),
            'payment' => $this->transformPayment($order),
            'pricing' => $this->transformPricing($order),
            'notes' => [
                'customer_note' => $order->notes,
                'internal_note' => $order->rejection_reason,
            ],
            'items' => $this->transformItems($order),
        ];
    }

    private function transformCustomer(Order $order): array
    {
        $user = $order->user;
        $phone = $user?->primaryPhone?->phone ?? $user?->phone;

        return [
            'id' => $user?->id,
            'name' => $user?->name,
            'phone' => $phone,
            'email' => $user?->email,
            'avatar_url' => null,
            'notes' => null,
        ];
    }

    private function transformDelivery(Order $order): array
    {
        $address = $order->user?->primaryAddress;
        $formatted = $address?->formatted ?? $order->delivery?->address;

        return [
            'type' => 'delivery',
            'address' => [
                'street' => $address?->street,
                'number' => $address?->number,
                'district' => $address?->district,
                'complement' => $address?->complement,
                'city' => $address?->city,
                'state' => $address?->state,
                'zip_code' => $address?->zip_code,
                'formatted' => $formatted,
            ],
            'distance_km' => null,
            'estimated_minutes' => $order->remaining_seconds !== null ? (int) ceil($order->remaining_seconds / 60) : null,
            'courier_name' => null,
            'courier_phone' => null,
        ];
    }

    private function transformPayment(Order $order): array
    {
        $method = (string) ($order->payment_method ?? '');

        return [
            'method' => $method !== '' ? $method : null,
            'label' => $this->paymentLabel($method),
            'status' => $order->payment_status,
            'change_for' => null,
            'paid_amount' => $order->payment_status === 'paid' ? (float) $order->total : null,
        ];
    }

    private function transformPricing(Order $order): array
    {
        return [
            'subtotal' => (float) $order->subtotal,
            'delivery_fee' => (float) $order->delivery_fee,
            'discount' => (float) $order->discount,
            'total' => (float) $order->total,
        ];
    }

    private function transformItems(Order $order): array
    {
        $cartItems = data_get($order->raw_payload, 'cart.items');

        if (! is_array($cartItems) || $cartItems === []) {
            $cartItems = data_get($order->raw_payload, 'order.items');
        }

        if (! is_array($cartItems) || $cartItems === []) {
            return $order->products->values()->map(function (Product $product, int $index): array {
                return [
                    'id' => $index + 1,
                    'product_id' => $product->id,
                    'name' => $product->name,
                    'quantity' => 1,
                    'unit_price' => (float) $product->price,
                    'addons_total' => 0.0,
                    'total_price' => (float) $product->price,
                    'image_url' => $product->image_url,
                    'notes' => null,
                    'variation' => null,
                    'addons' => [],
                ];
            })->all();
        }

        $products = $order->products->keyBy('id');

        return collect($cartItems)->values()->map(function (array $item, int $index) use ($products): array {
            $productId = (int) ($item['product_id'] ?? data_get($item, 'product.id', 0));
            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $basePrice = (float) ($item['base_price'] ?? data_get($item, 'unit_price', data_get($item, 'price', 0)));
            $addonsTotal = (float) ($item['additional_price'] ?? data_get($item, 'addons_total', 0));
            $product = $products->get($productId);
            $variationId = isset($item['variation_id']) ? (int) $item['variation_id'] : (isset($item['option_id']) ? (int) $item['option_id'] : null);
            $variationName = isset($item['variation_name']) ? (string) $item['variation_name'] : (isset($item['option_name']) ? (string) $item['option_name'] : null);

            return [
                'id' => $index + 1,
                'product_id' => $productId > 0 ? $productId : null,
                'name' => (string) ($item['product_name'] ?? data_get($item, 'name') ?? $product?->name ?? 'Produto'),
                'quantity' => $quantity,
                'unit_price' => round($basePrice + $addonsTotal, 2),
                'addons_total' => round($addonsTotal, 2),
                'total_price' => round(($basePrice + $addonsTotal) * $quantity, 2),
                'image_url' => $product?->image_url,
                'notes' => $item['observation'] ?? data_get($item, 'notes'),
                'variation' => $variationId !== null || $variationName !== null ? [
                    'id' => $variationId,
                    'name' => null,
                    'option_id' => $variationId,
                    'option_name' => $variationName,
                ] : null,
                'addons' => [],
            ];
        })->all();
    }

    private function paymentLabel(?string $method): ?string
    {
        return match ($method) {
            'pix' => 'Pix',
            'credit_card' => 'Cartao de credito',
            'debit_card' => 'Cartao de debito',
            'cash' => 'Dinheiro',
            null, '' => null,
            default => ucfirst(str_replace('_', ' ', $method)),
        };
    }

    private function queueStatusNotification(Order $order): void
    {
        $companyId = (int) ($order->company_id ?? 0);
        $phone = (string) ($order->user?->primaryPhone?->phone ?? $order->user?->phone ?? '');

        if ($companyId <= 0 || $phone === '') {
            return;
        }

        $this->orchestrator->queueStatusNotification(
            $companyId,
            $phone,
            'Pedido {order_code}: status {status}. Total R$ {total}.',
            [
                'order_code' => $order->code,
                'status' => $order->status,
                'total' => number_format((float) $order->total, 2, ',', '.'),
            ]
        );
    }
}
