<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Tenant\Concerns\ResolvesPizzaStore;
use App\Http\Requests\Api\StorePizzaAddonRequest;
use App\Http\Requests\Api\UpdatePizzaAddonRequest;
use App\Models\OptionalFlow;
use App\Models\OptionalFlowStepOption;
use App\Services\Pizzaria\PizzaAddonService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PizzaAddonController extends Controller
{
    use ResolvesPizzaStore;

    public function __construct(private readonly PizzaAddonService $addonService) {}

    public function index(Request $request, TenantContext $tenant): JsonResponse
    {
        $type = $this->validateType($request);
        $store = $this->resolvePizzaStore($tenant);

        return response()->json([
            'data' => $this->addonService->list($store, $type),
            'selection_limit' => $this->addonService->selectionLimit($store, $type),
        ]);
    }

    public function store(StorePizzaAddonRequest $request, TenantContext $tenant): JsonResponse
    {
        $store = $this->resolvePizzaStore($tenant);
        $data = $request->validated();

        $option = $this->addonService->create($store, $data['type'], [
            ...$data,
            'image' => $request->file('image'),
        ]);

        return response()->json($option, 201);
    }

    public function update(UpdatePizzaAddonRequest $request, OptionalFlowStepOption $option, TenantContext $tenant): JsonResponse
    {
        $this->ensureOwnership($option, $tenant);

        $option = $this->addonService->update($option, [
            ...$request->validated(),
            'image' => $request->file('image'),
        ]);

        return response()->json($option);
    }

    public function destroy(OptionalFlowStepOption $option, TenantContext $tenant): JsonResponse
    {
        $this->ensureOwnership($option, $tenant);
        $this->addonService->delete($option);

        return response()->json(['message' => 'Item removido com sucesso.']);
    }

    public function reorder(Request $request, TenantContext $tenant): JsonResponse
    {
        $type = $this->validateType($request);
        $store = $this->resolvePizzaStore($tenant);

        $data = $request->validate([
            'ordered_ids' => ['required', 'array'],
            'ordered_ids.*' => ['integer', 'exists:optional_flow_step_options,id'],
        ]);

        $this->addonService->reorder($store, $type, $data['ordered_ids']);

        return response()->json(['data' => $this->addonService->list($store, $type)]);
    }

    /**
     * "Limite de seleção" dos molhos (seção 12) — é uma configuração do bloco inteiro
     * (max_select do step), não de um item específico.
     */
    public function updateSelectionLimit(Request $request, TenantContext $tenant): JsonResponse
    {
        $type = $this->validateType($request);
        $store = $this->resolvePizzaStore($tenant);

        $data = $request->validate(['limit' => ['required', 'integer', 'min:1', 'max:10']]);
        $this->addonService->updateSelectionLimit($store, $type, $data['limit']);

        return response()->json(['selection_limit' => $this->addonService->selectionLimit($store, $type)]);
    }

    private function validateType(Request $request): string
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(OptionalFlow::PIZZA_FEATURE_TYPES)],
        ]);

        return $data['type'];
    }

    private function ensureOwnership(OptionalFlowStepOption $option, TenantContext $tenant): void
    {
        $store = $this->resolvePizzaStore($tenant);
        $flowStoreId = $option->step?->flow?->store_id;
        abort_unless($flowStoreId === $store->id, 404);
    }
}
