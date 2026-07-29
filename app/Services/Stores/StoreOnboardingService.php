<?php

namespace App\Services\Stores;

use App\Models\Store;
use App\Services\ImageUploadService;
use App\Services\Media\StoreBannerComposer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class StoreOnboardingService
{
    public function __construct(
        private readonly ImageUploadService $imageUploader,
        private readonly StoreBannerComposer $bannerComposer,
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
        // Loja sempre nasce em onboarding/oculta (createstore.md, "Fluxo esperado") —
        // só fica visível no WhatsApp depois de conectar o Mercado Pago (ou ser marcada
        // is_test_store pelo master). Regra de negócio, não depende de ambiente. Ver
        // Store::scopeVisibleOnWhatsapp().
        $payload['is_active'] = false;

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
            $logoFile = $request->file('logo');
            $store->logo_url = $this->imageUploader->upload($logoFile, $folder.'/logo', 600, 80);

            // Lojista mandou logo nova mas não escolheu um banner de cardápio próprio: gera
            // um banner padrão (logo centralizado sobre gradiente) em vez de deixar em
            // branco — mesma composição usada no card do carrossel do WhatsApp.
            if (! $request->hasFile('menu_banner') && blank($store->menu_banner_url)) {
                try {
                    $banner = $this->bannerComposer->compose(
                        (string) file_get_contents($logoFile->getRealPath()),
                        $store->category?->slug
                    );

                    $store->menu_banner_url = $this->imageUploader->uploadBinary($banner, $folder.'/banner-cardapio');
                } catch (\Throwable $e) {
                    Log::warning('Falha ao gerar banner de cardápio padrão.', ['store_id' => $store->id, 'error' => $e->getMessage()]);
                }
            }
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
