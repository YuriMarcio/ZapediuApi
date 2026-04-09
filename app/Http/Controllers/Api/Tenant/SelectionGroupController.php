<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Models\SelectionGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SelectionGroupController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $groups = SelectionGroup::query()
            ->with(['store:id,name', 'options', 'products:id,name,selection_group_id'])
            ->when($request->filled('store_id'), fn ($q) => $q->where('store_id', (int) $request->query('store_id')))
            ->orderBy('name')
            ->get();

        return response()->json($groups);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatePayload($request);

        $group = DB::transaction(function () use ($data): SelectionGroup {
            $group = SelectionGroup::query()->create([
                'store_id' => $data['store_id'] ?? null,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'display_type' => $data['display_type'] ?? 'custom',
                'is_required' => $data['is_required'] ?? true,
                'is_active' => $data['is_active'] ?? true,
            ]);

            $this->syncOptions($group, $data['options'] ?? []);

            return $group;
        });

        return response()->json($this->loadGroup($group), 201);
    }

    public function show(SelectionGroup $selectionGroup): JsonResponse
    {
        return response()->json($this->loadGroup($selectionGroup));
    }

    public function update(Request $request, SelectionGroup $selectionGroup): JsonResponse
    {
        $data = $this->validatePayload($request, true);

        DB::transaction(function () use ($selectionGroup, $data): void {
            $attributes = [];

            foreach (['store_id', 'name', 'description', 'display_type', 'is_required', 'is_active'] as $field) {
                if (array_key_exists($field, $data)) {
                    $attributes[$field] = $data[$field];
                }
            }

            if ($attributes !== []) {
                $selectionGroup->fill($attributes)->save();
            }

            if (array_key_exists('options', $data)) {
                $this->syncOptions($selectionGroup, $data['options'] ?? []);
            }
        });

        return response()->json($this->loadGroup($selectionGroup->refresh()));
    }

    public function destroy(SelectionGroup $selectionGroup): JsonResponse
    {
        $selectionGroup->delete();

        return response()->json(['message' => 'Grupo de selecao removido com sucesso.']);
    }

    private function validatePayload(Request $request, bool $isUpdate = false): array
    {
        return $request->validate([
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'name' => [$isUpdate ? 'sometimes' : 'required', 'required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:255'],
            'display_type' => ['sometimes', 'string', Rule::in(['custom', 'size', 'weight', 'type', 'presentation'])],
            'is_required' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'options' => ['nullable', 'array', 'max:30'],
            'options.*.label' => ['required_with:options', 'string', 'max:120'],
            'options.*.description' => ['nullable', 'string', 'max:255'],
            'options.*.price' => ['nullable', 'numeric', 'min:0'],
            'options.*.position' => ['sometimes', 'integer', 'min:1'],
            'options.*.is_active' => ['sometimes', 'boolean'],
        ]);
    }

    private function syncOptions(SelectionGroup $selectionGroup, array $options): void
    {
        $selectionGroup->options()->delete();

        foreach (array_values($options) as $index => $optionData) {
            $selectionGroup->options()->create([
                'label' => $optionData['label'],
                'description' => $optionData['description'] ?? null,
                'price' => $optionData['price'] ?? 0,
                'position' => $optionData['position'] ?? ($index + 1),
                'is_active' => $optionData['is_active'] ?? true,
            ]);
        }
    }

    private function loadGroup(SelectionGroup $selectionGroup): SelectionGroup
    {
        return $selectionGroup->load([
            'store:id,name',
            'options',
            'products:id,name,selection_group_id',
        ]);
    }
}