<?php

namespace App\Services\Pizzaria;

use App\Models\OptionalFlowStepOption;
use App\Models\Store;
use App\Services\ImageUploadService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class PizzaAddonService
{
    public function __construct(
        private readonly PizzaOptionalFlowProvisioner $provisioner,
        private readonly ImageUploadService $imageUploader,
    ) {}

    /**
     * Lista compacta dos itens de um tipo (bordas/ingredientes/molhos) — todos pertencem
     * ao único step do fluxo auto-provisionado daquele tipo.
     */
    public function list(Store $store, string $type): Collection
    {
        $flow = $this->provisioner->ensureFlow($store, $type);
        $step = $flow->steps->first();

        return $step?->options()->with('sizePrices')->get() ?? new Collection();
    }

    public function selectionLimit(Store $store, string $type): int
    {
        $flow = $this->provisioner->ensureFlow($store, $type);

        return (int) ($flow->steps->first()?->max_select ?? 1);
    }

    public function updateSelectionLimit(Store $store, string $type, int $limit): void
    {
        $flow = $this->provisioner->ensureFlow($store, $type);
        $flow->steps->first()?->update(['max_select' => max(1, $limit)]);
    }

    /**
     * @param  array{
     *     title: string, image?: ?UploadedFile, is_active?: bool,
     *     pricing_mode?: string, available_size_ids?: ?array<int>,
     *     price?: float, size_prices?: array<int, float>, max_quantity?: ?int,
     * }  $data
     */
    public function create(Store $store, string $type, array $data): OptionalFlowStepOption
    {
        return DB::transaction(function () use ($store, $type, $data) {
            $flow = $this->provisioner->ensureFlow($store, $type);
            $step = $flow->steps->first();

            $option = $step->options()->create([
                'source_type' => 'custom',
                'title' => $data['title'],
                'image_url' => isset($data['image']) ? $this->uploadImage($store, $type, $data['image']) : null,
                'is_active' => $data['is_active'] ?? true,
                'pricing_mode' => $data['pricing_mode'] ?? 'single',
                'available_size_ids' => $data['available_size_ids'] ?? null,
                'price' => $data['price'] ?? 0,
                'base_price' => $data['price'] ?? 0,
                'max_quantity' => $data['max_quantity'] ?? null,
                'position' => ((int) $step->options()->max('position')) + 1,
            ]);

            $this->syncSizePrices($option, $data['size_prices'] ?? []);

            return $option->refresh()->load('sizePrices');
        });
    }

    public function update(OptionalFlowStepOption $option, array $data): OptionalFlowStepOption
    {
        return DB::transaction(function () use ($option, $data) {
            $store = $option->step->flow->store;

            $option->fill([
                'title' => $data['title'] ?? $option->title,
                'is_active' => $data['is_active'] ?? $option->is_active,
                'pricing_mode' => $data['pricing_mode'] ?? $option->pricing_mode,
                'available_size_ids' => array_key_exists('available_size_ids', $data) ? $data['available_size_ids'] : $option->available_size_ids,
                'max_quantity' => array_key_exists('max_quantity', $data) ? $data['max_quantity'] : $option->max_quantity,
            ]);

            if (array_key_exists('price', $data)) {
                $option->price = $data['price'];
                $option->base_price = $data['price'];
            }

            if (isset($data['image'])) {
                $option->image_url = $this->uploadImage($store, $option->step->flow->feature_type, $data['image']);
            }

            $option->save();

            if (array_key_exists('size_prices', $data)) {
                $this->syncSizePrices($option, $data['size_prices'] ?? []);
            }

            return $option->refresh()->load('sizePrices');
        });
    }

    public function delete(OptionalFlowStepOption $option): void
    {
        $option->delete();
    }

    public function reorder(Store $store, string $type, array $orderedIds): void
    {
        foreach (array_values($orderedIds) as $index => $optionId) {
            OptionalFlowStepOption::query()
                ->whereHas('step.flow', fn ($q) => $q->where('store_id', $store->id)->forFeature($type))
                ->where('id', $optionId)
                ->update(['position' => $index + 1]);
        }
    }

    /**
     * @param  array<int, float>  $sizePrices  [store_pizza_size_id => price]
     */
    private function syncSizePrices(OptionalFlowStepOption $option, array $sizePrices): void
    {
        $option->sizePrices()->delete();

        foreach ($sizePrices as $sizeId => $price) {
            $option->sizePrices()->create([
                'store_pizza_size_id' => (int) $sizeId,
                'price' => $price,
            ]);
        }
    }

    private function uploadImage(Store $store, string $type, UploadedFile $image): string
    {
        return $this->imageUploader->upload($image, $store->mediaFolderName().'/opcionais/'.$type, 600, 80);
    }
}
