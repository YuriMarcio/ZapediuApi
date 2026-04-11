<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Tenant\RequestWalletAdvanceRequest;
use App\Models\Company;
use App\Services\Payments\WalletService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

class WalletController extends Controller
{
    public function summary(TenantContext $tenant, WalletService $wallet): JsonResponse
    {
        $company = Company::query()->with('plan')->findOrFail($tenant->companyId());

        return response()->json($wallet->summary($company));
    }

    public function requestAdvance(
        RequestWalletAdvanceRequest $request,
        TenantContext $tenant,
        WalletService $wallet
    ): JsonResponse {
        $company = Company::query()->with('plan')->findOrFail($tenant->companyId());
        $advance = $wallet->requestAdvance(
            $company,
            $request->user(),
            (float) $request->input('amount'),
            $request->input('notes'),
            $request,
        );

        return response()->json([
            'message' => 'Solicitacao de antecipacao registrada com sucesso.',
            'advance' => $advance,
        ], 201);
    }
}