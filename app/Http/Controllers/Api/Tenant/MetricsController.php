<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Services\Analytics\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MetricsController extends Controller
{
    public function __construct(private readonly DashboardService $dashboard) {}

    /**
     * GET /tenant/analytics/overview
     *
     * Full Início screen payload:
     *   - revenue card (today, yesterday, % change, sparkline, avg_ticket, totals)
    *   - order_counts (pending, accepted, preparing, delivering, done)
     *   - active_orders (latest 10)
     *   - hourly_distribution (series + peak_hour + promo_window)
     *   - sales_by_category (revenue + % per category for today)
     */
    public function overview(): JsonResponse
    {
        return response()->json($this->dashboard->overview());
    }

    /**
     * GET /tenant/analytics/revenue?months=6
     *
     * Monthly revenue for the last N months (default 6).
     * Used by the detailed charts section.
     */
    public function revenue(Request $request): JsonResponse
    {
        $months = (int) $request->query('months', 6);

        return response()->json([
            'series' => $this->dashboard->monthlyRevenue(max(1, min(24, $months))),
        ]);
    }

    /**
     * GET /tenant/analytics/hourly
     *
     * Today's order distribution by hour.
     * Used to render the "Distribuição por Hora" chart standalone.
     */
    public function hourly(): JsonResponse
    {
        return response()->json(
            $this->dashboard->hourlyDistribution(\Illuminate\Support\Carbon::today())
        );
    }

    /**
     * GET /tenant/analytics/categories
     *
     * Today's revenue breakdown per category.
     * Used to render "Vendas por Categoria" standalone.
     */
    public function categories(): JsonResponse
    {
        return response()->json(
            $this->dashboard->salesByCategory(\Illuminate\Support\Carbon::today())
        );
    }

    /**
     * GET /tenant/analytics/whatsapp
     *
     * WhatsApp button-click conversion funnel.
     */
    public function whatsapp(): JsonResponse
    {
        return response()->json($this->dashboard->whatsappConversion());
    }

    /**
     * @deprecated  Kept for backward compatibility — use /analytics/overview instead.
     */
    public function dashboard(): JsonResponse
    {
        return $this->overview();
    }
}
