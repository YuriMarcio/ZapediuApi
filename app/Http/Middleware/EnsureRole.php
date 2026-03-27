<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(Response::HTTP_UNAUTHORIZED, 'Usuario nao autenticado.');
        }

        if ($roles !== [] && ! in_array((string) $user->role, $roles, true)) {
            abort(Response::HTTP_FORBIDDEN, 'Permissao insuficiente para esta operacao.');
        }

        return $next($request);
    }
}
