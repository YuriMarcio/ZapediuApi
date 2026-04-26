<?php

namespace App\Services\Stores;

use App\Models\Store;
use App\Services\ImageUploadService;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class StoreOnboardingService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly ImageUploadService $imageUploader,
    ) {
    }

    public function listForUser(Request $request)
    {
        return Store::query()
            ->with('owner:id,name,email')
            ->where('user_id', $request->user()->id)
            ->orderBy('name')
            ->get();
    }

    public function create(array $data, Request $request): Store
    {
        $payload = $data;
        $payload['user_id'] = $request->user()->id;
        $payload['slug'] = $this->uniqueSlug((string) $data['name']);
        $payload['is_active'] = true;

        // Logo
        $logo = $request->file('logo');
        if ($logo !== null) {
            $path = $this->imageUploader->upload($logo, 'logos', 600, 80);
            $payload['logo_url'] = \Storage::disk('r2')->url($path);
        }

        // Banner/Cover
        $cover = $request->file('cover');
        if ($cover !== null) {
            $path = $this->imageUploader->upload($cover, 'covers', 1200, 400);
            $payload['cover_image_url'] = \Storage::disk('r2')->url($path);
        }

        $store = Store::query()->create($payload);

        $this->auditLogger->log('store.created', [
            'entity_type' => Store::class,
            'entity_id' => $store->id,
            'changes' => $store->toArray(),
        ], $request);

        return $store->refresh();
    }

    public function updateIdentity(Store $store, array $data, $request)
    {
        // Upload do Logo para o R2
        if ($request->hasFile('logo')) {
            // Se já existir um antigo, deleta do R2 para não acumular lixo
            if ($store->logo_url) {
                // Não é possível deletar por URL, mas se quiser pode implementar lógica extra
            }

            // Salva no R2 dentro da pasta 'logos'
            $path = $request->file('logo')->store('logos', 'r2');
            $store->logo_url = \Storage::disk('r2')->url($path);
        }

        // Upload da Capa para o R2
        if ($request->hasFile('cover_image')) {
            if ($store->cover_image_url) {
                // Não é possível deletar por URL, mas se quiser pode implementar lógica extra
            }

            $path = $request->file('cover_image')->store('covers', 'r2');
            $store->cover_image_url = \Storage::disk('r2')->url($path);
        }

        $store->fill($data);
        $store->save();

        return $store;
    }

    public function updateAddress(Store $store, array $data, Request $request): Store
    {
        $store->fill($data)->save();

        $this->auditLogger->log('store.address.updated', [
            'entity_type' => Store::class,
            'entity_id' => $store->id,
            'changes' => $data,
        ], $request);

        return $store->refresh();
    }

    public function updateHours(Store $store, array $hours, Request $request): Store
    {
        $store->business_hours = $hours['business_hours'];
        $store->save();

        $this->auditLogger->log('store.hours.updated', [
            'entity_type' => Store::class,
            'entity_id' => $store->id,
            'changes' => $hours,
        ], $request);

        return $store->refresh();
    }

    private function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name);
        $slug = $base !== '' ? $base : 'loja';
        $suffix = 1;

        while (Store::query()
            ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->where('slug', $slug)
            ->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
