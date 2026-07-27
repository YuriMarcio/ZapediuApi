<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Lead;
use App\Models\Operation;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class CommercialDashboardController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $operation = $this->resolveOperation($request, $user);

        $companies = Company::query()
            ->with('plan:id,fee_percent,fee_fixed')
            ->when($user->role === 'manager', fn ($query) => $query->where('manager_id', $user->id))
            ->when($operation !== null, fn ($query) => $query->where('operation_id', $operation->id))
            ->get(['id', 'seller_id', 'plan_id']);

        $companyIds = $companies->pluck('id');
        $stores = Store::withoutGlobalScopes()
            ->whereIn('company_id', $companyIds)
            ->get(['id', 'company_id', 'name', 'is_active']);

        $orders = Order::withoutGlobalScopes()
            ->whereIn('company_id', $companyIds)
            ->where('status', '!=', 'cancelled')
            ->whereNotIn('payment_status', ['cancelled', 'failed', 'rejected'])
            ->get(['id', 'company_id', 'store_id', 'product_ids', 'total']);

        $ordersByCompany = $orders->groupBy('company_id');
        $platformRevenue = $companies->sum(function (Company $company) use ($ordersByCompany): float {
            $companyOrders = $ordersByCompany->get($company->id, collect());
            $gmv = (float) $companyOrders->sum('total');

            return ($gmv * ((float) ($company->plan?->fee_percent ?? 0) / 100))
                + ($companyOrders->count() * (float) ($company->plan?->fee_fixed ?? 0));
        });

        $leads = Lead::query()
            ->when($user->role === 'manager', fn ($query) => $query->where('manager_id', $user->id))
            ->when($operation !== null, fn ($query) => $query->where('operation_id', $operation->id))
            ->get(['id', 'assigned_seller_id', 'stage']);

        $sellers = User::query()
            ->where('role', 'seller')
            ->when($user->role === 'manager', fn ($query) => $query->where('manager_id', $user->id))
            ->orderBy('name')
            ->get(['id', 'name', 'monthly_store_goal']);

        $companiesBySeller = $companies->filter(fn (Company $company) => $company->seller_id !== null)->groupBy('seller_id');
        $leadsBySeller = $leads->filter(fn (Lead $lead) => $lead->assigned_seller_id !== null)->groupBy('assigned_seller_id');
        $recentOrderStoreIds = $this->recentOrderStoreIds($companyIds);

        return response()->json([
            'data' => [
                'operation' => $operation === null ? null : [
                    'id' => $operation->id,
                    'name' => $operation->name,
                    'coverage_area' => $operation->coverage_area,
                    'city' => $operation->city,
                    'state' => $operation->state,
                ],
                'stores' => [
                    'direct' => $stores->filter(fn (Store $store) => $companies->firstWhere('id', $store->company_id)?->seller_id === null)->count(),
                    'team' => $stores->filter(fn (Store $store) => $companies->firstWhere('id', $store->company_id)?->seller_id !== null)->count(),
                    'active' => $stores->where('is_active', true)->count(),
                    'pending' => $stores->where('is_active', false)->count(),
                    'without_recent_orders' => $stores
                        ->where('is_active', true)
                        ->whereNotIn('id', $recentOrderStoreIds)
                        ->values()
                        ->map(fn (Store $store) => ['id' => $store->id, 'name' => $store->name])
                        ->all(),
                ],
                'orders' => [
                    'count' => $orders->count(),
                    'gmv' => round((float) $orders->sum('total'), 2),
                    'platform_revenue' => round((float) $platformRevenue, 2),
                ],
                'leads' => [
                    'total' => $leads->count(),
                    'converted' => $leads->where('stage', 'loja_ativada')->count(),
                    'conversion_rate' => $leads->isEmpty()
                        ? 0.0
                        : round(($leads->where('stage', 'loja_ativada')->count() / $leads->count()) * 100, 1),
                ],
                'commissions' => [
                    'pending' => 0.0,
                    'approved' => 0.0,
                    'paid' => 0.0,
                ],
                'seller_performance' => $sellers->map(function (User $seller) use ($companiesBySeller, $leadsBySeller): array {
                    /** @var Collection<int, Lead> $sellerLeads */
                    $sellerLeads = $leadsBySeller->get($seller->id, collect());

                    return [
                        'id' => $seller->id,
                        'name' => $seller->name,
                        'goal' => (int) ($seller->monthly_store_goal ?? 0),
                        'stores' => $companiesBySeller->get($seller->id, collect())->count(),
                        'commission' => 0.0,
                        'leads_assigned' => $sellerLeads->count(),
                        'leads_converted' => $sellerLeads->where('stage', 'loja_ativada')->count(),
                    ];
                })->values()->all(),
                'top_categories' => $this->topCategories($orders),
            ],
        ]);
    }

    private function resolveOperation(Request $request, User $user): ?Operation
    {
        $operationId = $request->integer('operation_id');
        if ($operationId === 0) {
            return null;
        }

        $operation = Operation::query()
            ->when($user->role === 'manager', fn ($query) => $query->where('commercial_manager_id', $user->id))
            ->find($operationId);

        abort_unless($operation !== null, 404, 'Operação não encontrada.');

        return $operation;
    }

    /** @param Collection<int, int> $companyIds
     * @return Collection<int, int>
     */
    private function recentOrderStoreIds(Collection $companyIds): Collection
    {
        return Order::withoutGlobalScopes()
            ->whereIn('company_id', $companyIds)
            ->where('status', '!=', 'cancelled')
            ->whereNotIn('payment_status', ['cancelled', 'failed', 'rejected'])
            ->where('ordered_at', '>=', now()->subDays(30))
            ->whereNotNull('store_id')
            ->pluck('store_id');
    }

    /** @param Collection<int, Order> $orders
     * @return array<int, array{name: string, count: int}>
     */
    private function topCategories(Collection $orders): array
    {
        $productIds = $orders
            ->pluck('product_ids')
            ->filter(fn (mixed $ids): bool => is_array($ids))
            ->flatten()
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->values();

        if ($productIds->isEmpty()) {
            return [];
        }

        $products = Product::withoutGlobalScopes()
            ->with('category:id,name')
            ->whereIn('id', $productIds->unique())
            ->get(['id', 'category_id', 'category'])
            ->keyBy('id');

        return $productIds
            ->map(fn (int $productId) => $products->get($productId))
            ->filter()
            ->countBy(fn (Product $product): string => $product->category?->name ?? $product->category ?? 'Sem categoria')
            ->sortDesc()
            ->take(5)
            ->map(fn (int $count, string $name): array => ['name' => $name, 'count' => $count])
            ->values()
            ->all();
    }
}
