<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Wallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class WalletController extends Controller
{
    /**
     * GET /tenant/wallet/summary
     * Retorna o resumo da carteira do lojista logado.
     */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $company = $user->company;
        $wallet = $company->wallet ?? Wallet::where('company_id', $company->id)->first();

        if (!$wallet) {
            return response()->json([
                'message' => 'Carteira não encontrada para esta empresa.'
            ], 404);
        }

        return response()->json([
            'balance_pix' => $wallet->balance_pix,
            'balance_card' => $wallet->balance_card,
            'balance_total' => $wallet->balance_total,
            'is_enabled_withdrawal' => $wallet->is_enabled_withdrawal,
            'is_active' => $wallet->is_active,
            'mp_integration' => $wallet->hasMpIntegration(),
            'can_withdraw' => $wallet->canWithdraw(),
            'plan_id' => $wallet->plan_id,
        ]);
    }

    /**
     * POST /tenant/wallet/advances
     * Solicita antecipação de recebíveis do cartão.
     */
    public function requestAdvance(Request $request): JsonResponse
    {
        $user = $request->user();
        $company = $user->company;
        $wallet = $company->wallet ?? Wallet::where('company_id', $company->id)->first();

        if (!$wallet) {
            return response()->json([
                'message' => 'Carteira não encontrada para esta empresa.'
            ], 404);
        }

        // Aqui você pode implementar a lógica de solicitação de antecipação
        // Exemplo: criar um registro de antecipação, disparar evento, etc.

        return response()->json([
            'message' => 'Solicitação de antecipação recebida com sucesso.'
        ]);
    }
}
