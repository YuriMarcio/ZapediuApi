<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Wallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;

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
            'mp_client_id' => $this->mercadoPagoOAuthConfigured() ? config('services.mercadopago.client_id') : null,
            'mp_redirect_uri' => $this->mercadoPagoOAuthConfigured() ? config('services.mercadopago.redirect_uri') : null,
            'mp_oauth_state' => $this->mercadoPagoOAuthConfigured() ? $this->issueOAuthState((int) $company->id) : null,
        ]);
    }

    /**
     * `state` do OAuth do Mercado Pago, emitido pro lojista AUTENTICADO e devolvido
     * intacto pelo MP no callback.
     *
     * Precisa ser assinado, não o company_id cru: o callback é uma rota pública que
     * confia no `state` pra decidir QUAL carteira recebe o token. Com o id em texto,
     * qualquer pessoa autorizaria a própria conta do Mercado Pago passando o id de
     * outra empresa e sobrescreveria a carteira dela — todo o faturamento da loja
     * passaria a cair na conta do atacante. Encriptado, o valor só pode ter sido
     * emitido aqui, pra quem estava logado naquela empresa.
     */
    private function issueOAuthState(int $companyId): string
    {
        return Crypt::encryptString(json_encode([
            'company_id' => $companyId,
            'exp' => now()->addMinutes(30)->getTimestamp(),
        ]));
    }

    /**
     * O .env deste projeto usa strings de exemplo ("seu_client_id_aqui", "seu-dominio")
     * como placeholder até o lojista/admin configurar credenciais reais do Mercado Pago.
     * Sem isso, o botão de conectar não deve ficar habilitado no painel.
     */
    private function mercadoPagoOAuthConfigured(): bool
    {
        $clientId = (string) config('services.mercadopago.client_id');
        $redirectUri = (string) config('services.mercadopago.redirect_uri');

        return $clientId !== ''
            && $redirectUri !== ''
            && ! str_contains($clientId, 'seu_')
            && ! str_contains($redirectUri, 'seu-dominio');
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
