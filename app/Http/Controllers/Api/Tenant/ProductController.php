<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreProductRequest;
use App\Http\Requests\Api\UpdateProductRequest;
use App\Models\Company;
use App\Models\Product;
use App\Models\Store;
use App\Services\Stock\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ProductController extends Controller
{
    public function __construct(private readonly StockService $stockService) {}

    /**
     * GET /tenant/products
     *
     * Query params:
     *   search      – partial match on name/description
     *   category_id – filter by category
     *   is_active   – 1 / 0 (omit = all)
     *   per_page    – default 15
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->stockService->listProducts($request));
    }

    /**
     * POST /tenant/products
     */
    public function store(StoreProductRequest $request): JsonResponse
    {
        $data       = $request->safe()->except('variations', 'image', 'optional_flow_id');
        $variations = (array) $request->input('variations', []);
        $image      = $request->file('image');

        $product = $this->stockService->createProduct($data, $variations, $image, $request);

        if ($request->has('optional_flow_id')) {
            $this->stockService->syncOptionalFlowAssignment($product, $request->integer('optional_flow_id') ?: null);
        }

        return response()->json($product, 201);
    }

    /**
     * POST /tenant/stores/{store}/products/import-json
     *
     * Recebe diretamente uma lista JSON no formato
     * [{"codigo":"8.610","produto":"DIPIRONA 1G 10CPDS"}].
     * O código é preservado como SKU. Como a planilha de origem não informa
     * preço, itens novos entram inativos com preço R$ 0,00 até serem revisados.
     */
    public function importJson(Request $request, Store $store): JsonResponse
    {
        $this->ensureStoreAccess($request, $store);

        $payload = $request->all();
        $items = array_is_list($payload) ? $payload : ($payload['products'] ?? null);
        $validated = Validator::make(['products' => $items], [
            'products' => ['required', 'array', 'min:1', 'max:1000'],
            'products.*.codigo' => ['required', 'string', 'max:80'],
            'products.*.produto' => ['required', 'string', 'max:100'],
        ])->validate();

        $summary = DB::transaction(function () use ($validated, $store): array {
            $created = 0;
            $updated = 0;

            foreach ($validated['products'] as $item) {
                $sku = trim($item['codigo']);
                $name = trim($item['produto']);
                $product = Product::withoutGlobalScopes()
                    ->where('store_id', $store->id)
                    ->where('name', $name)
                    ->first();

                if ($product !== null) {
                    $product->update(['sku' => $sku]);
                    $updated++;
                    continue;
                }

                Product::withoutGlobalScopes()->create([
                    'company_id' => $store->company_id,
                    'store_id' => $store->id,
                    'name' => $name,
                    'sku' => $sku,
                    'description' => null,
                    'price' => 0,
                    'stock_quantity' => 0,
                    'track_stock' => false,
                    'is_active' => false,
                ]);
                $created++;
            }

            return ['created' => $created, 'updated' => $updated];
        });

        return response()->json([
            'message' => "Importação concluída: {$summary['created']} produto(s) criado(s) e {$summary['updated']} atualizado(s). Itens novos ficam inativos até receberem preço.",
            ...$summary,
        ]);
    }

    /**
     * GET /tenant/products/{product}
     */
    public function show(Product $product): JsonResponse
    {
        $product->load([
            'category:id,name,color,slug',
            'selectionGroup:id,name,display_type,is_required,is_active',
            'selectionGroup.options:id,selection_group_id,label,description,price,position,is_active',
            'variationGroup:id,name,required',
            'variationGroup.options:id,variation_group_id,name,price,sort_order',
            'variations',
            'optionalFlows:id',
        ]);

        // Hide circular relationships to prevent infinite recursion
        if ($product->selectionGroup) {
            $product->selectionGroup->makeHidden('products');
        }
        if ($product->variationGroup) {
            $product->variationGroup->makeHidden('products');
        }
        if ($product->category) {
            $product->category->makeHidden('products');
        }

        $data = $product->toArray();
        $data['optional_flow_id'] = $product->optionalFlows->first()?->id;
        $data['pizza_addon_option_ids'] = $product->optionalFlowStepOptions()->pluck('optional_flow_step_options.id');

        return response()->json($data);
    }

    /**
     * PUT /tenant/products/{product}
     */
    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $data       = $request->safe()->except('variations', 'image', 'optional_flow_id');
        $variations = (array) $request->input('variations', []);
        $image      = $request->file('image');

        $product = $this->stockService->updateProduct($product, $data, $variations, $image, $request);

        if ($request->has('optional_flow_id')) {
            $this->stockService->syncOptionalFlowAssignment($product, $request->integer('optional_flow_id') ?: null);
        }

        return response()->json($product);
    }

    /**
     * DELETE /tenant/products/{product}
     */
    public function destroy(Product $product, Request $request): JsonResponse
    {
        $this->stockService->deleteProduct($product, $request);

        return response()->json(['message' => 'Produto removido com sucesso.']);
    }

    /**
     * PUT /tenant/products/{product}/pizza-addons
     *
     * Sincroniza o vínculo item-a-item de bordas/ingredientes/molhos desse sabor
     * (seção 13 da spec de pizzaria). Body: { option_ids: number[] } — lista vazia
     * equivale a "Remover todos".
     */
    public function updatePizzaAddons(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'option_ids' => ['present', 'array'],
            'option_ids.*' => ['integer', 'exists:optional_flow_step_options,id'],
        ]);

        $product->optionalFlowStepOptions()->sync($data['option_ids']);

        return response()->json([
            'data' => $product->optionalFlowStepOptions()->get(['optional_flow_step_options.id']),
        ]);
    }

    private function ensureStoreAccess(Request $request, Store $store): void
    {
        $user = $request->user();

        if ($user->role === 'master') {
            return;
        }

        $company = Company::query()->find($store->company_id);
        $hasAccess = $company !== null && match ($user->role) {
            'owner' => $company->id === $user->company_id,
            'seller' => $company->seller_id === $user->id,
            'manager' => $company->manager_id === $user->id,
            default => false,
        };

        abort_unless($hasAccess, 404, 'Loja não encontrada.');
        if (in_array($user->role, ['seller', 'manager'], true)) {
            abort_unless($store->hasActiveManagerAccess(), 403, 'Seu período de acesso a esta loja foi encerrado.');
        }
    }
}
