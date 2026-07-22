<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Tenant\Concerns\ResolvesPizzaStore;
use App\Http\Requests\Api\StorePizzaSizeRequest;
use App\Http\Requests\Api\UpdatePizzaSizeRequest;
use App\Models\StorePizzaSize;
use App\Services\Pizzaria\PizzaConfigService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PizzaSizeController extends Controller
{
    use ResolvesPizzaStore;

    public function __construct(private readonly PizzaConfigService $configService) {}

    public function store(StorePizzaSizeRequest $request, TenantContext $tenant): JsonResponse
    {
        $store = $this->resolvePizzaStore($tenant);
        $size = $this->configService->createSize($store, $request->validated());

        return response()->json($size, 201);
    }

    public function update(UpdatePizzaSizeRequest $request, StorePizzaSize $size, TenantContext $tenant): JsonResponse
    {
        $this->ensureOwnership($size, $tenant);

        return response()->json($this->configService->updateSize($size, $request->validated()));
    }

    /**
     * Com ?dry_run=1, apenas mostra o impacto (produtos afetados) sem excluir de fato —
     * usado para o preview de exclusão antes de confirmar no modal.
     */
    public function destroy(Request $request, StorePizzaSize $size, TenantContext $tenant): JsonResponse
    {
        $this->ensureOwnership($size, $tenant);

        if ($request->boolean('dry_run')) {
            return response()->json($this->configService->sizeDeleteImpact($size));
        }

        $this->configService->deleteSize($size);

        return response()->json(['message' => 'Tamanho removido com sucesso.']);
    }

    public function reorder(Request $request, TenantContext $tenant): JsonResponse
    {
        $store = $this->resolvePizzaStore($tenant);
        $data = $request->validate([
            'ordered_ids' => ['required', 'array'],
            'ordered_ids.*' => ['integer', 'exists:store_pizza_sizes,id'],
        ]);

        $this->configService->reorderSizes($store, $data['ordered_ids']);

        return response()->json(['data' => $this->configService->listSizes($store->refresh())]);
    }

    private function ensureOwnership(StorePizzaSize $size, TenantContext $tenant): void
    {
        $store = $this->resolvePizzaStore($tenant);
        abort_unless($size->store_id === $store->id, 404);
    }
}
