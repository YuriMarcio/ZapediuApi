<?php

namespace App\Services\Analytics;

use App\Models\Order;
use App\Models\Product;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    public function __construct(private readonly TenantContext $tenant) {}

    // ── Main dashboard payload ───────────────────────────────────────────────

    /**
     * Everything the Início screen needs in one roundtrip.
     */
    public function overview(): array
    {
        $today     = Carbon::today();
        $yesterday = Carbon::yesterday();

        return [
            'revenue'             => $this->revenueCard($today, $yesterday),
            'order_counts'        => $this->orderCounts($today),
            'active_orders'       => $this->activeOrders(),
            'hourly_distribution' => $this->hourlyDistribution($today),
            'sales_by_category'   => $this->salesByCategory($today),
        ];
    }

    // ── Revenue card ─────────────────────────────────────────────────────────

    /**
     * Today's revenue, yesterday's revenue, sparkline (hourly today), % change.
     */
    public function revenueCard(Carbon $today, Carbon $yesterday): array
    {
        $todayRevenue = $this->revenueForDay($today);
        $yesterdayRevenue = $this->revenueForDay($yesterday);

        $pctChange = $yesterdayRevenue > 0
            ? round((($todayRevenue - $yesterdayRevenue) / $yesterdayRevenue) * 100, 1)
            : null;

        return [
            'today'           => round($todayRevenue, 2),
            'yesterday'       => round($yesterdayRevenue, 2),
            'pct_change'      => $pctChange,
            'sparkline'       => $this->sparkline($today),
            'avg_ticket'      => $this->avgTicket($today),
            'total_orders'    => $this->countOrders($today, null),
            'cancelled_orders'=> $this->countOrders($today, 'cancelled'),
        ];
    }

    // ── Order status counts ──────────────────────────────────────────────────

    /**
     * Pendentes / Aceitos / Em Preparo / Em Entrega / Finalizados.
     * "Finalizados" = done all-time (total ever closed, not just today).
     */
    public function orderCounts(Carbon $today): array
    {
        $active = Order::query()
            ->whereIn('status', ['pending', 'accepted', 'preparing', 'delivering'])
            ->where('payment_status', 'paid')
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $done = Order::query()->where('status', 'done')->count();

        return [
            'pending' => (int) ($active['pending'] ?? 0),
            'accepted' => (int) ($active['accepted'] ?? 0),
            'preparing' => (int) ($active['preparing'] ?? 0),
            'delivering' => (int) ($active['delivering'] ?? 0),
            'done' => $done,
        ];
    }

    // ── Active orders preview ────────────────────────────────────────────────

    /**
     * Latest 10 active orders for the "Pedidos Ativos" list.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Order>
     */
    public function activeOrders(): \Illuminate\Database\Eloquent\Collection
    {
        $orders = Order::query()
            ->with(['user:id,name,email,phone'])
            ->whereIn('status', ['pending', 'accepted', 'preparing', 'delivering'])
            ->where('payment_status', 'paid')
            ->orderByDesc('ordered_at')
            ->limit(10)
            ->get([
                'id', 'user_id', 'code', 'status',
                'total', 'ordered_at', 'estimated_ready_at',
                'product_ids',
            ]);

        $this->attachProducts($orders);

        return $orders;
    }

    // ── Hourly distribution ──────────────────────────────────────────────────

    /**
     * Orders per hour for today (0–23).
     * Includes peak_hour and best_promotion_window (hour with most orders).
     */
    public function hourlyDistribution(Carbon $today): array
    {
        $rows = DB::table('orders')
            ->when($this->tenant->hasCompany(), fn ($q) => $q->where('company_id', $this->tenant->companyId()))
            ->whereDate('ordered_at', $today)
            ->selectRaw('HOUR(ordered_at) as hour, count(*) as total')
            ->groupBy('hour')
            ->orderBy('hour')
            ->get()
            ->keyBy('hour');

        $series = [];
        for ($h = 0; $h <= 23; $h++) {
            $series[$h] = (int) ($rows[$h]->total ?? 0);
        }

        $peakHour = array_search(max($series), $series, true);

        // "Best promotion window" = peak hour and the following hour
        $promoEnd = min(23, (int) $peakHour + 1);

        return [
            'series'            => $series,          // array[0..23] → count
            'peak_hour'         => $peakHour,
            'promo_window'      => "{$peakHour}h–{$promoEnd}h",
        ];
    }

    // ── Sales by category ────────────────────────────────────────────────────

    /**
     * Revenue and percentage per category for today.
     */
    public function salesByCategory(Carbon $today): array
    {
        $orders = Order::query()
            ->when($this->tenant->hasCompany(), fn ($q) => $q->where('company_id', $this->tenant->companyId()))
            ->whereDate('orders.ordered_at', $today)
            ->whereNotIn('orders.status', ['cancelled'])
            ->get(['id', 'product_ids']);

        $productIds = $orders
            ->pluck('product_ids')
            ->filter(fn ($ids) => is_array($ids) && $ids !== [])
            ->flatten()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $products = Product::query()
            ->with('category:id,name,color')
            ->whereIn('id', $productIds)
            ->get(['id', 'name', 'category', 'category_id', 'price'])
            ->keyBy('id');

        $rows = [];

        foreach ($orders as $order) {
            foreach ((array) $order->product_ids as $productId) {
                $product = $products->get((int) $productId);

                if ($product === null) {
                    continue;
                }

                $category = $product->category?->name ?? $product->category ?? 'Sem categoria';
                $color = $product->category?->color ?? '#6b7280';
                $key = $category.'|'.$color;

                $rows[$key] ??= [
                    'category' => $category,
                    'color' => $color,
                    'revenue' => 0.0,
                ];

                $rows[$key]['revenue'] += (float) $product->price;
            }
        }

        $rows = collect($rows)->sortByDesc('revenue')->values();
        $total = (float) $rows->sum('revenue');

        return $rows->map(fn (array $row) => [
            'category' => $row['category'],
            'color' => $row['color'],
            'revenue' => round($row['revenue'], 2),
            'pct' => $total > 0 ? round(($row['revenue'] / $total) * 100, 1) : 0.0,
        ])->all() + ['total' => round($total, 2)];
    }

    // ── Monthly revenue (chart) ───────────────────────────────────────────────

    /**
     * Monthly revenue for the last N months — used by the charts section.
     */
    public function monthlyRevenue(int $months = 6): array
    {
        $since = Carbon::today()->subMonths($months - 1)->startOfMonth();

        return Order::query()
            ->selectRaw("strftime('%Y-%m', ordered_at) as month")
            ->selectRaw('ROUND(SUM(total), 2) as revenue')
            ->whereNotIn('status', ['cancelled'])
            ->where('ordered_at', '>=', $since)
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->toArray();
    }

    // ── WhatsApp conversion ──────────────────────────────────────────────────

    public function whatsappConversion(): array
    {
        $total     = \App\Models\WhatsappClickEvent::query()->count();
        $converted = \App\Models\WhatsappClickEvent::query()->where('converted', true)->count();

        return [
            'clicks'          => $total,
            'converted_clicks'=> $converted,
            'conversion_rate' => $total > 0 ? round(($converted / $total) * 100, 2) : 0.0,
        ];
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function revenueForDay(Carbon $day): float
    {
        return (float) Order::query()
            ->whereDate('ordered_at', $day)
            ->whereNotIn('status', ['cancelled'])
            ->sum('total');
    }

    private function avgTicket(Carbon $day): float
    {
        $result = Order::query()
            ->whereDate('ordered_at', $day)
            ->whereNotIn('status', ['cancelled'])
            ->avg('total');

        return round((float) $result, 2);
    }

    private function countOrders(Carbon $day, ?string $status): int
    {
        return Order::query()
            ->whereDate('ordered_at', $day)
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->count();
    }

    /**
     * Hourly revenue sparkline for a given day (returns floats 0–23).
     *
     * @return array<int, float>
     */
    private function sparkline(Carbon $day): array
    {
        $rows = DB::table('orders')
            ->when($this->tenant->hasCompany(), fn ($q) => $q->where('company_id', $this->tenant->companyId()))
            ->whereDate('ordered_at', $day)
            ->whereNotIn('status', ['cancelled'])
            ->selectRaw('HOUR(ordered_at) as hour, ROUND(SUM(total), 2) as revenue')
            ->groupBy('hour')
            ->orderBy('hour')
            ->get()
            ->keyBy('hour');

        $series = [];
        for ($h = 0; $h <= 23; $h++) {
            $series[$h] = (float) ($rows[$h]->revenue ?? 0);
        }

        return $series;
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
            ->get(['id', 'name', 'price', 'image_path'])
            ->keyBy('id');

        foreach ($orders as $order) {
            $order->setRelation(
                'products',
                collect($order->product_ids ?? [])
                    ->map(fn ($productId) => $products->get((int) $productId))
                    ->filter()
                    ->values()
            );
        }
    }
}
