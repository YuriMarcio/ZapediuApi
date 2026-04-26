<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreAddressRequest;
use App\Http\Requests\Api\StoreHoursRequest;
use App\Http\Requests\Api\StoreIdentityRequest;
use App\Models\Store;
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
            return $data;
        });
        return response()->json($result);
    }

    public function store(StoreIdentityRequest $request): JsonResponse
    {
        $store = $this->stores->create($request->validated(), $request);

        return response()->json($store, 201);
    }

    public function show(Store $store): JsonResponse
    {
        return response()->json($store->load('owner:id,name,email'));
    }

    public function updateIdentity(Request $request, Store $store): JsonResponse
    {
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
        ]);
    }

    public function updateAddress(StoreAddressRequest $request, Store $store): JsonResponse
    {
        return response()->json($this->stores->updateAddress($store, $request->validated(), $request));
    }

    public function updateHours(StoreHoursRequest $request, Store $store): JsonResponse
    {
        return response()->json($this->stores->updateHours($store, $request->validated(), $request));
    }
}
