<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Models\VariationGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VariationGroupController extends Controller
{
    public function index(): JsonResponse
    {
        $groups = VariationGroup::query()
            ->with('options')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $groups->map(fn (VariationGroup $group): array => $this->transformGroup($group))->values()->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatePayload($request);

        $group = DB::transaction(function () use ($data): VariationGroup {
            $group = VariationGroup::query()->create([
                'name' => $data['name'],
                'required' => $data['required'] ?? true,
            ]);

            $this->syncOptions($group, $data['options']);

            return $group;
        });

        return response()->json($this->transformGroup($group->load('options')), 201);
    }

    public function update(Request $request, VariationGroup $variation): JsonResponse
    {
        $data = $this->validatePayload($request, true);

        DB::transaction(function () use ($variation, $data): void {
            $variation->fill([
                'name' => $data['name'],
                'required' => $data['required'] ?? false,
            ])->save();

            $this->syncOptions($variation, $data['options'] ?? []);
        });

        return response()->json($this->transformGroup($variation->refresh()->load('options')));
    }

    public function destroy(VariationGroup $variation): JsonResponse
    {
        DB::transaction(function () use ($variation): void {
            $variation->products()->update(['variation_group_id' => null]);
            $variation->delete();
        });

        return response()->json([], 204);
    }

    private function validatePayload(Request $request, bool $isUpdate = false): array
    {
        return $request->validate([
            'name' => [$isUpdate ? 'sometimes' : 'required', 'required', 'string', 'max:160'],
            'required' => ['sometimes', 'boolean'],
            'options' => [$isUpdate ? 'sometimes' : 'required', 'array', 'min:1'],
            'options.*.id' => ['nullable', 'integer'],
            'options.*.name' => ['required_with:options', 'string', 'max:120'],
            'options.*.price' => ['required_with:options', 'numeric', 'min:0'],
            'options.*.sort_order' => ['sometimes', 'integer', 'min:1'],
        ]);
    }

    private function syncOptions(VariationGroup $group, array $options): void
    {
        $keptIds = [];

        foreach (array_values($options) as $index => $payload) {
            $attributes = [
                'name' => $payload['name'],
                'price' => $payload['price'],
                'sort_order' => $payload['sort_order'] ?? ($index + 1),
            ];

            $option = null;

            if (! empty($payload['id'])) {
                $option = $group->options()->whereKey((int) $payload['id'])->first();
            }

            if ($option !== null) {
                $option->fill($attributes)->save();
                $keptIds[] = $option->id;
                continue;
            }

            $option = $group->options()->create($attributes);
            $keptIds[] = $option->id;
        }

        $group->options()->whereNotIn('id', $keptIds)->delete();
    }

    private function transformGroup(VariationGroup $group): array
    {
        return [
            'id' => $group->id,
            'name' => $group->name,
            'required' => (bool) $group->required,
            'options' => $group->options->map(fn ($option): array => [
                'id' => $option->id,
                'name' => $option->name,
                'price' => (float) $option->price,
            ])->values()->all(),
            'created_at' => optional($group->created_at)?->toISOString(),
            'updated_at' => optional($group->updated_at)?->toISOString(),
        ];
    }
}