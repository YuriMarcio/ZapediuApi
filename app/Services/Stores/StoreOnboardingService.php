<?php

namespace App\Services\Stores;

use App\Models\Store;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class StoreOnboardingService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

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

        $store = Store::query()->create($payload);

        $this->auditLogger->log('store.created', [
            'entity_type' => Store::class,
            'entity_id' => $store->id,
            'changes' => $store->toArray(),
        ], $request);

        return $store->refresh();
    }

    public function updateIdentity(Store $store, array $data, Request $request): Store
    {
        $store->fill($data);

        if (isset($data['name']) && $data['name'] !== $store->getOriginal('name')) {
            $store->slug = $this->uniqueSlug((string) $data['name'], $store->id);
        }

        $store->save();

        $this->auditLogger->log('store.identity.updated', [
            'entity_type' => Store::class,
            'entity_id' => $store->id,
            'changes' => $data,
        ], $request);

        return $store->refresh();
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