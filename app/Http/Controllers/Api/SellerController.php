<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class SellerController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        if (! $this->authorized($request)) {
            return response()->json(['message' => 'Unauthorized.'], Response::HTTP_UNAUTHORIZED);
        }

        $data = $request->validate([
            'name'  => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);

        $code = $this->generateSellerCode();

        $seller = User::query()->create([
            'name'        => $data['name'],
            'email'       => $data['email'],
            'phone'       => $data['phone'] ?? null,
            'password'    => bcrypt(Str::random(32)),
            'role'        => 'seller',
            'seller_code' => $code,
            'is_admin'    => false,
        ]);

        return response()->json([
            'message' => 'Vendedor criado com sucesso.',
            'data'    => [
                'id'          => $seller->id,
                'name'        => $seller->name,
                'email'       => $seller->email,
                'phone'       => $seller->phone,
                'role'        => $seller->role,
                'seller_code' => $seller->seller_code,
            ],
        ], Response::HTTP_CREATED);
    }

    private function generateSellerCode(): string
    {
        do {
            $code = 'VENDEDOR-'.strtoupper(Str::random(6));
        } while (User::query()->where('seller_code', $code)->exists());

        return $code;
    }

    private function authorized(Request $request): bool
    {
        $token = trim((string) config('services.admin.api_token'));

        if ($token === '') {
            return true;
        }

        $provided = (string) $request->header('X-Admin-Token', '');

        return hash_equals($token, $provided);
    }
}
