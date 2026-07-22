<?php

namespace App\Services\Stores;

use App\Models\Store;
use App\Services\ImageUploadService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class StoreOnboardingService
{
    public function __construct(
        private readonly ImageUploadService $imageUploader,
    ) {
    }

    public function listForUser(Request $request)
    {
        $user = $request->user();

        return Store::query()
            ->withoutGlobalScopes()
            ->with('owner:id,name,email')
            ->when($user->role === 'seller', fn ($query) => $query->whereHas('company', fn ($company) => $company->where('seller_id', $user->id)))
            ->when($user->role === 'manager', fn ($query) => $query->whereHas('company', fn ($company) => $company->where('manager_id', $user->id)))
            ->when($user->role === 'owner', fn ($query) => $query->where('company_id', $user->company_id))
            ->when(! in_array($user->role, ['master', 'seller', 'manager', 'owner'], true), fn ($query) => $query->whereRaw('1 = 0'))
            ->orderBy('name')
            ->get();
    }

    public function create(array $data, Request $request): Store
    {
        $payload = $data;
        $payload['user_id'] = $request->user()->id;
        $payload['slug'] = $this->uniqueSlug((string) $data['name']);
        // Loja só fica elegível para receber pedidos depois que o lojista conectar o
        // Mercado Pago (ver MercadoPagoController::handleCallback). Fora de produção
        // essa exigência é liberada para não travar o ambiente de dev.
        $payload['is_active'] = ! app()->isProduction();

        // Cria a loja primeiro para ter o ID usado na organização das pastas de mídia.
        $store = Store::query()->create($payload);

        $this->uploadIdentityImages($store, $request);
        $store->save();

        return $store->refresh();
    }

    public function updateIdentity(Store $store, array $data, $request)
    {
        $this->uploadIdentityImages($store, $request);

        unset($data['logo'], $data['cover'], $data['menu_banner']);

        $store->fill($data);
        $store->save();

        return $store;
    }

    /**
     * Uploads logo/capa/banner into {store_id}/perfil/... onto the in-memory
     * model (caller is responsible for saving).
     */
    private function uploadIdentityImages(Store $store, $request): void
    {
        $folder = $store->mediaFolderName().'/perfil';

        if ($request->hasFile('logo')) {
            $store->logo_url = $this->imageUploader->upload($request->file('logo'), $folder.'/logo', 600, 80);
        }

        if ($request->hasFile('cover')) {
            $store->cover_image_url = $this->imageUploader->upload($request->file('cover'), $folder.'/capa', 1200, 400);
        }

        if ($request->hasFile('menu_banner')) {
            $store->menu_banner_url = $this->imageUploader->upload($request->file('menu_banner'), $folder.'/banner-cardapio', 1200, 400);
        }
    }

    public function updateAddress(Store $store, array $data, Request $request): Store
    {
        $store->fill($data)->save();
        return $store->refresh();
    }

    public function updateHours(Store $store, array $hours, Request $request): Store
    {
        $store->business_hours = $hours['business_hours'];
        $store->save();

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
