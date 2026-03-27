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

class StoreController extends Controller
{
    public function __construct(private readonly StoreOnboardingService $stores) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->stores->listForUser($request));
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

    public function updateIdentity(StoreIdentityRequest $request, Store $store): JsonResponse
    {
        return response()->json($this->stores->updateIdentity($store, $request->validated(), $request));
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