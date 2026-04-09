<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\OptionalFlow;
use App\Models\OptionalFlowStep;
use App\Models\OptionalFlowStepOption;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OptionalFlowController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $flows = OptionalFlow::query()
            ->with(['store:id,name', 'products:id,name', 'categories:id,name,slug,color', 'steps.options.product:id,name,price', 'steps.options.category:id,name,slug,color'])
            ->when($request->filled('store_id'), fn ($q) => $q->where('store_id', (int) $request->query('store_id')))
            ->orderByDesc('id')
            ->get();

        return response()->json($flows->map(fn (OptionalFlow $flow): array => $this->transformFlow($flow)));
    }

    public function catalog(Request $request): JsonResponse
    {
        $products = Product::query()
            ->when($request->filled('store_id'), fn ($q) => $q->where('store_id', (int) $request->query('store_id')))
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'store_id', 'category_id', 'name', 'price', 'image_path']);

        $categories = Category::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'color']);

        return response()->json([
            'products' => $products,
            'categories' => $categories,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatePayload($request);

        $flow = DB::transaction(function () use ($data): OptionalFlow {
            $flow = OptionalFlow::query()->create([
                'store_id' => $data['store_id'] ?? null,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ]);

            $this->syncAssignments($flow, $data['assignments'] ?? []);
            $this->syncSteps($flow, $data['steps'] ?? []);

            return $flow;
        });

        return response()->json($this->transformFlow($this->loadFlow($flow)), 201);
    }

    public function show(OptionalFlow $flow): JsonResponse
    {
        return response()->json($this->transformFlow($this->loadFlow($flow)));
    }

    public function update(Request $request, OptionalFlow $flow): JsonResponse
    {
        $data = $this->validatePayload($request, true);

        DB::transaction(function () use ($flow, $data): void {
            $attributes = [];

            if (array_key_exists('store_id', $data)) {
                $attributes['store_id'] = $data['store_id'];
            }

            if (array_key_exists('name', $data)) {
                $attributes['name'] = $data['name'];
            }

            if (array_key_exists('description', $data)) {
                $attributes['description'] = $data['description'];
            }

            if (array_key_exists('is_active', $data)) {
                $attributes['is_active'] = $data['is_active'];
            }

            if ($attributes !== []) {
                $flow->fill($attributes)->save();
            }

            if (array_key_exists('assignments', $data)) {
                $this->syncAssignments($flow, $data['assignments'] ?? []);
            }

            if (array_key_exists('steps', $data)) {
                $this->syncSteps($flow, $data['steps'] ?? []);
            }
        });

        return response()->json($this->transformFlow($this->loadFlow($flow->refresh())));
    }

    public function configure(Request $request, OptionalFlow $flow): JsonResponse
    {
        $data = $request->validate([
            'is_active' => ['sometimes', 'boolean'],
            'steps' => ['nullable', 'array'],
            'steps.*.id' => ['required_with:steps', 'integer'],
            'steps.*.items' => ['nullable', 'array'],
            'steps.*.items.*.id' => ['nullable', 'integer'],
            'steps.*.items.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'steps.*.items.*.title' => ['nullable', 'string', 'max:160'],
            'steps.*.items.*.description' => ['nullable', 'string', 'max:255'],
            'steps.*.items.*.base_price' => ['nullable', 'numeric', 'min:0'],
            'steps.*.items.*.merchant_price' => ['nullable', 'numeric', 'min:0'],
            'steps.*.items.*.is_active' => ['sometimes', 'boolean'],
            'steps.*.items.*.position' => ['sometimes', 'integer', 'min:1'],
        ]);

        DB::transaction(function () use ($flow, $data): void {
            if (array_key_exists('is_active', $data)) {
                $flow->is_active = (bool) $data['is_active'];
                $flow->save();
            }

            foreach ($data['steps'] ?? [] as $stepPayload) {
                $step = $flow->steps()->whereKey((int) $stepPayload['id'])->first();

                if ($step === null) {
                    continue;
                }

                $items = array_values($stepPayload['items'] ?? []);

                if ($step->items_source === 'merchant') {
                    $step->options()->delete();

                    foreach ($items as $index => $itemPayload) {
                        $title = $itemPayload['title'] ?? null;

                        if ($title === null && ! empty($itemPayload['product_id'])) {
                            $title = Product::query()->whereKey((int) $itemPayload['product_id'])->value('name');
                        }

                        $basePrice = array_key_exists('base_price', $itemPayload)
                            ? (float) $itemPayload['base_price']
                            : (float) (Product::query()->whereKey((int) ($itemPayload['product_id'] ?? 0))->value('price') ?? 0);

                        $merchantPrice = array_key_exists('merchant_price', $itemPayload)
                            ? $itemPayload['merchant_price']
                            : null;

                        $step->options()->create([
                            'source_type' => 'product',
                            'product_id' => $itemPayload['product_id'] ?? null,
                            'title' => $title,
                            'description' => $itemPayload['description'] ?? null,
                            'price' => $merchantPrice ?? $basePrice,
                            'base_price' => $basePrice,
                            'merchant_price' => $merchantPrice,
                            'is_active' => $itemPayload['is_active'] ?? true,
                            'position' => $itemPayload['position'] ?? ($index + 1),
                        ]);
                    }

                    continue;
                }

                foreach ($items as $itemPayload) {
                    if (empty($itemPayload['id'])) {
                        continue;
                    }

                    $option = $step->options()->whereKey((int) $itemPayload['id'])->first();

                    if ($option === null) {
                        continue;
                    }

                    $basePrice = array_key_exists('base_price', $itemPayload)
                        ? (float) $itemPayload['base_price']
                        : (float) ($option->base_price ?? $option->price ?? 0);

                    $merchantPrice = array_key_exists('merchant_price', $itemPayload)
                        ? $itemPayload['merchant_price']
                        : $option->merchant_price;

                    $option->fill([
                        'product_id' => $itemPayload['product_id'] ?? $option->product_id,
                        'title' => $itemPayload['title'] ?? $option->title,
                        'description' => $itemPayload['description'] ?? $option->description,
                        'base_price' => $basePrice,
                        'merchant_price' => $merchantPrice,
                        'price' => $merchantPrice ?? $basePrice,
                        'is_active' => $itemPayload['is_active'] ?? $option->is_active,
                        'position' => $itemPayload['position'] ?? $option->position,
                    ])->save();
                }
            }
        });

        return response()->json($this->transformFlow($this->loadFlow($flow->refresh())));
    }

    public function destroy(OptionalFlow $flow): JsonResponse
    {
        $flow->delete();

        return response()->json(['message' => 'Fluxo removido com sucesso.']);
    }

    private function validatePayload(Request $request, bool $isUpdate = false): array
    {
        return $request->validate([
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'name' => [$isUpdate ? 'sometimes' : 'required', 'required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'assignments' => ['nullable', 'array'],
            'assignments.*.type' => ['required_with:assignments', 'string', Rule::in(['product', 'category'])],
            'assignments.*.id' => ['required_with:assignments', 'integer'],
            'steps' => ['nullable', 'array'],
            'steps.*.title' => ['required_with:steps', 'string', 'max:160'],
            'steps.*.description' => ['nullable', 'string', 'max:255'],
            'steps.*.trigger_when' => ['nullable', 'string', 'max:160'],
            'steps.*.customer_hint' => ['nullable', 'string', 'max:255'],
            'steps.*.items_source' => ['sometimes', 'string', Rule::in(['system', 'merchant'])],
            'steps.*.allow_price_override' => ['sometimes', 'boolean'],
            'steps.*.is_required' => ['sometimes', 'boolean'],
            'steps.*.charge_type' => ['sometimes', 'string', Rule::in(['free', 'paid'])],
            'steps.*.min_select' => ['sometimes', 'integer', 'min:0'],
            'steps.*.max_select' => ['sometimes', 'integer', 'min:1'],
            'steps.*.position' => ['sometimes', 'integer', 'min:1'],
            'steps.*.options' => ['nullable', 'array'],
            'steps.*.options.*.source_type' => ['required_with:steps.*.options', 'string', Rule::in(['custom', 'product', 'category'])],
            'steps.*.options.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'steps.*.options.*.category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'steps.*.options.*.title' => ['nullable', 'string', 'max:160'],
            'steps.*.options.*.description' => ['nullable', 'string', 'max:255'],
            'steps.*.options.*.base_price' => ['nullable', 'numeric', 'min:0'],
            'steps.*.options.*.merchant_price' => ['nullable', 'numeric', 'min:0'],
            'steps.*.options.*.price' => ['nullable', 'numeric', 'min:0'],
            'steps.*.options.*.is_active' => ['sometimes', 'boolean'],
            'steps.*.options.*.position' => ['sometimes', 'integer', 'min:1'],
        ]);
    }

    private function syncAssignments(OptionalFlow $flow, array $assignments): void
    {
        $productIds = [];
        $categoryIds = [];

        foreach ($assignments as $assignment) {
            if (($assignment['type'] ?? null) === 'product') {
                $productIds[] = (int) $assignment['id'];
            }

            if (($assignment['type'] ?? null) === 'category') {
                $categoryIds[] = (int) $assignment['id'];
            }
        }

        $flow->products()->sync($productIds);
        $flow->categories()->sync($categoryIds);
    }

    private function syncSteps(OptionalFlow $flow, array $steps): void
    {
        $flow->steps()->delete();

        foreach (array_values($steps) as $index => $stepData) {
            $step = $flow->steps()->create([
                'title' => $stepData['title'],
                'description' => $stepData['description'] ?? null,
                'trigger_when' => $stepData['trigger_when'] ?? null,
                'customer_hint' => $stepData['customer_hint'] ?? null,
                'items_source' => $stepData['items_source'] ?? 'system',
                'allow_price_override' => $stepData['allow_price_override'] ?? false,
                'is_required' => $stepData['is_required'] ?? false,
                'charge_type' => $stepData['charge_type'] ?? 'free',
                'min_select' => $stepData['min_select'] ?? 0,
                'max_select' => $stepData['max_select'] ?? 1,
                'position' => $stepData['position'] ?? ($index + 1),
            ]);

            foreach (array_values($stepData['options'] ?? []) as $optionIndex => $optionData) {
                $title = $optionData['title'] ?? null;

                if (($optionData['source_type'] ?? null) === 'product' && ! empty($optionData['product_id'])) {
                    $title = $title ?: Product::query()->whereKey((int) $optionData['product_id'])->value('name');
                }

                if (($optionData['source_type'] ?? null) === 'category' && ! empty($optionData['category_id'])) {
                    $title = $title ?: Category::query()->whereKey((int) $optionData['category_id'])->value('name');
                }

                $basePrice = array_key_exists('base_price', $optionData)
                    ? (float) $optionData['base_price']
                    : (float) ($optionData['price'] ?? 0);

                $merchantPrice = $optionData['merchant_price'] ?? null;

                $step->options()->create([
                    'source_type' => $optionData['source_type'],
                    'product_id' => $optionData['product_id'] ?? null,
                    'category_id' => $optionData['category_id'] ?? null,
                    'title' => $title,
                    'description' => $optionData['description'] ?? null,
                    'price' => $merchantPrice ?? $basePrice,
                    'base_price' => $basePrice,
                    'merchant_price' => $merchantPrice,
                    'is_active' => $optionData['is_active'] ?? true,
                    'position' => $optionData['position'] ?? ($optionIndex + 1),
                ]);
            }
        }
    }

    private function loadFlow(OptionalFlow $flow): OptionalFlow
    {
        return $flow->load([
            'store:id,name',
            'products:id,name',
            'categories:id,name,slug,color',
            'steps.options.product:id,name,price',
            'steps.options.category:id,name,slug,color',
        ]);
    }

    private function transformFlow(OptionalFlow $flow): array
    {
        return [
            'id' => $flow->id,
            'name' => $flow->name,
            'description' => $flow->description,
            'is_active' => (bool) $flow->is_active,
            'store_id' => $flow->store_id,
            'store' => $flow->store ? [
                'id' => $flow->store->id,
                'name' => $flow->store->name,
            ] : null,
            'assignments' => [
                'products' => $flow->products->map(fn (Product $product): array => [
                    'id' => $product->id,
                    'name' => $product->name,
                ])->values()->all(),
                'categories' => $flow->categories->map(fn (Category $category): array => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                ])->values()->all(),
            ],
            'steps' => $flow->steps->map(fn (OptionalFlowStep $step): array => [
                'id' => $step->id,
                'title' => $step->title,
                'description' => $step->description,
                'trigger_when' => $step->trigger_when,
                'customer_hint' => $step->customer_hint,
                'items_source' => $step->items_source ?? 'system',
                'allow_price_override' => (bool) ($step->allow_price_override ?? false),
                'is_required' => (bool) $step->is_required,
                'charge_type' => $step->charge_type,
                'min_select' => (int) $step->min_select,
                'max_select' => (int) $step->max_select,
                'position' => (int) $step->position,
                'items' => $step->options->map(fn (OptionalFlowStepOption $option): array => [
                    'id' => $option->id,
                    'source_type' => $option->source_type,
                    'title' => $option->title,
                    'description' => $option->description,
                    'product_id' => $option->product_id,
                    'category_id' => $option->category_id,
                    'base_price' => number_format((float) ($option->base_price ?? $option->price ?? 0), 2, '.', ''),
                    'merchant_price' => $option->merchant_price !== null
                        ? number_format((float) $option->merchant_price, 2, '.', '')
                        : null,
                    'effective_price' => number_format((float) ($option->merchant_price ?? $option->base_price ?? $option->price ?? 0), 2, '.', ''),
                    'is_active' => (bool) $option->is_active,
                    'position' => (int) $option->position,
                    'product' => $option->product ? [
                        'id' => $option->product->id,
                        'name' => $option->product->name,
                        'price' => number_format((float) $option->product->price, 2, '.', ''),
                    ] : null,
                    'category' => $option->category ? [
                        'id' => $option->category->id,
                        'name' => $option->category->name,
                        'slug' => $option->category->slug,
                    ] : null,
                ])->values()->all(),
            ])->values()->all(),
        ];
    }
}
