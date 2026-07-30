<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

/**
 * Guarda das rotas `/admin/*` (criar seller, endpoints admin, CRUD de planos).
 *
 * Esse grupo de rotas não tem middleware — a checagem é aqui, e por isso ela precisa
 * falhar FECHADA. A versão anterior devolvia `true` quando o token não estava
 * configurado: bastava alguém apagar ADMIN_API_TOKEN do ambiente "pra limpar" e as
 * rotas de administração ficavam abertas na internet, sem nenhum aviso. Sem token
 * configurado, ninguém entra.
 */
trait AuthorizesAdminRequests
{
    private function authorized(Request $request): bool
    {
        $token = trim((string) config('services.admin.api_token'));

        if ($token === '') {
            return false;
        }

        $provided = (string) $request->header('X-Admin-Token', '');

        return $provided !== '' && hash_equals($token, $provided);
    }
}
