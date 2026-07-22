<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Tenant\Concerns\ResolvesPizzaStore;
use App\Http\Requests\Api\UpdateCategoryPricesRequest;
use App\Http\Requests\Api\UpdatePizzaConfigRequest;
use App\Models\Category;
use App\Services\Pizzaria\PizzaConfigService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

class PizzaConfigController extends Controller
{
    use ResolvesPizzaStore;

    public function __construct(private readonly PizzaConfigService $configService) {}

    public function show(TenantContext $tenant): JsonResponse
    {
        $store = $this->resolvePizzaStore($tenant);

        return response()->json($this->configService->getConfig($store));
    }

    public function update(UpdatePizzaConfigRequest $request, TenantContext $tenant): JsonResponse
    {
        $store = $this->resolvePizzaStore($tenant);
        $this->configService->updateSettings($store, $request->validated());

        return response()->json($this->configService->getConfig($store->refresh()));
    }

    public function categoryPrices(TenantContext $tenant): JsonResponse
    {
        $store = $this->resolvePizzaStore($tenant);

        return response()->json(['data' => $this->configService->categoryPriceMatrix($store)]);
    }

    public function updateCategoryPrices(UpdateCategoryPricesRequest $request, Category $category, TenantContext $tenant): JsonResponse
    {
        $store = $this->resolvePizzaStore($tenant);
        abort_unless($category->company_id === $store->company_id, 404);

        $this->configService->updateCategoryPrices($category, $request->validated()['prices']);

        return response()->json(['data' => $this->configService->categoryPriceMatrix($store)]);
    }

    public function reviewAlerts(TenantContext $tenant): JsonResponse
    {
        $store = $this->resolvePizzaStore($tenant);

        return response()->json($this->configService->reviewAlerts($store));
    }
}
