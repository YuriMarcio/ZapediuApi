<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\Company;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    public function login(LoginRequest $request, AuditLogger $auditLogger): JsonResponse
    {
        $companyToken = (string) $request->input('company_token', '');
        $company = $companyToken !== '' ? Company::query()->where('api_token', $companyToken)->first() : null;

        $user = User::query()
            ->when($company !== null, fn ($query) => $query->where('company_id', $company->id))
            ->where('email', (string) $request->string('email'))
            ->first();

        if ($user === null || ! Hash::check((string) $request->input('password'), $user->password)) {
            return response()->json([
                'message' => 'Credenciais invalidas.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        /** @var TenantContext $tenant */
        $tenant = app(TenantContext::class);
        $tenant->setCompanyId($user->company_id);

        $deviceName = (string) $request->input('device_name', 'api-client');
        $token = $user->createToken($deviceName);

        $auditLogger->log('auth.login', [
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'entity_type' => User::class,
            'entity_id' => $user->id,
        ], $request);

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'company_id' => $user->company_id,
            ],
        ]);
    }

    public function logout(AuditLogger $auditLogger): JsonResponse
    {
        $token = request()->user()?->currentAccessToken();

        if ($token !== null) {
            $token->delete();
        }

        $auditLogger->log('auth.logout', [
            'entity_type' => User::class,
            'entity_id' => request()->user()?->id,
        ], request());

        return response()->json(['message' => 'Sessao encerrada com sucesso.']);
    }
}
