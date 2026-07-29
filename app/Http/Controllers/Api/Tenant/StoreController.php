<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreAddressRequest;
use App\Http\Requests\Api\StoreHoursRequest;
use App\Http\Requests\Api\StoreIdentityRequest;
use App\Models\Store;
use App\Models\Company;
use App\Services\Stores\StoreOnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class StoreController extends Controller
{
    public function __construct(private readonly StoreOnboardingService $stores)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $stores = $this->stores->listForUser($request);
        $result = $stores->map(function ($store) {
            $data = $store->toArray();
            $data['store_id'] = $store->id;
            $data['size_template'] = $store->sizeTemplate();
            return $data;
        });
        return response()->json(['data' => $result->values()]);
    }

    public function store(StoreIdentityRequest $request): JsonResponse
    {
        $store = $this->stores->create($request->validated(), $request);

        return response()->json($store, 201);
    }

    public function show(Store $store): JsonResponse
    {
        $this->ensureStoreAccess(request(), $store);
        $data = $store->load('owner:id,name,email')->toArray();
        $data['size_template'] = $store->sizeTemplate();

        return response()->json($data);
    }

    public function updateIdentity(Request $request, Store $store): JsonResponse
    {
        $this->ensureStoreAccess($request, $store);
        // Usar StoreIdentityRequest para validação e atualizar normalmente
        // O método espera StoreIdentityRequest, então altere o tipo do parâmetro
        // e garanta que a request está validando corretamente
        //
        // O método correto:
        // public function updateIdentity(StoreIdentityRequest $request, Store $store): JsonResponse
        //
        // Mas para não quebrar a rota, vamos validar manualmente:

        $validated = app(\App\Http\Requests\Api\StoreIdentityRequest::class)->validated();
        $store = $this->stores->updateIdentity($store, $validated, $request);

        return response()->json([
            'id'         => $store->id,
            'name'       => $store->name,
            'trade_name' => $store->name,
            'slug'       => $store->slug,
            'logo_path'  => $store->logo_path,
            'logo_url'   => $store->logo_url,
            'cover_image_path' => $store->cover_image_path,
            'cover_image_url'  => $store->cover_image_url,
            'menu_banner_url'  => $store->menu_banner_url,
            'business_type'    => $store->business_type,
            'max_flavors'      => $store->max_flavors,
            'size_template'    => $store->sizeTemplate(),
        ]);
    }

    public function updateAddress(StoreAddressRequest $request, Store $store): JsonResponse
    {
        $this->ensureStoreAccess($request, $store);
        return response()->json($this->stores->updateAddress($store, $request->validated(), $request));
    }

    public function updateHours(StoreHoursRequest $request, Store $store): JsonResponse
    {
        $this->ensureStoreAccess($request, $store);
        return response()->json($this->stores->updateHours($store, $request->validated(), $request));
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

        if (in_array($user->role, ['seller', 'manager'], true) && ! $store->hasActiveManagerAccess()) {
            abort(403, 'Seu período de acesso a esta loja foi encerrado. Solicite a liberação ao administrador.');
        }
    }
}
