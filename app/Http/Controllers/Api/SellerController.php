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
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $sellers = User::query()
            ->where('role', 'seller')
            ->when($user->role !== 'master', fn ($query) => $query->where('manager_id', $user->id))
            ->withCount('soldCompanies')
            ->orderBy('name')
            ->get()
            ->map(fn (User $seller) => $this->sellerData($seller));

        return response()->json(['data' => $sellers]);
    }

    public function store(Request $request): JsonResponse
    {
        $isCommercialRequest = $request->user() !== null;
        if (! $isCommercialRequest && ! $this->authorized($request)) {
            return response()->json(['message' => 'Unauthorized.'], Response::HTTP_UNAUTHORIZED);
        }

        $data = $request->validate([
            'name'  => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:30'],
            'commercial_zone' => ['nullable', 'string', 'max:255'],
            'monthly_store_goal' => ['nullable', 'integer', 'min:1', 'max:999'],
        ]);

        $code = $this->generateSellerCode();

        $seller = User::query()->create([
            'name'        => $data['name'],
            'email'       => $data['email'],
            'phone'       => $data['phone'] ?? null,
            'password'    => bcrypt(Str::random(32)),
            'role'        => 'seller',
            'seller_code' => $code,
            'manager_id'  => $isCommercialRequest && $request->user()->role === 'manager' ? $request->user()->id : null,
            'commercial_zone' => $data['commercial_zone'] ?? null,
            'monthly_store_goal' => $data['monthly_store_goal'] ?? 10,
            'is_active' => true,
            'is_admin'    => false,
        ]);

        return response()->json([
            'message' => 'Vendedor criado com sucesso.',
            'data'    => $this->sellerData($seller),
        ], Response::HTTP_CREATED);
    }

    public function updateStatus(Request $request, User $seller): JsonResponse
    {
        $user = $request->user();

        abort_unless(
            $seller->role === 'seller' && ($user->role === 'master' || $seller->manager_id === $user->id),
            Response::HTTP_NOT_FOUND,
            'Vendedor não encontrado.',
        );

        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $seller->update(['is_active' => $data['is_active']]);

        return response()->json(['data' => $this->sellerData($seller->refresh())]);
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

    private function sellerData(User $seller): array
    {
        return [
            'id' => $seller->id,
            'name' => $seller->name,
            'email' => $seller->email,
            'phone' => $seller->phone,
            'seller_code' => $seller->seller_code,
            'commercial_zone' => $seller->commercial_zone,
            'monthly_store_goal' => $seller->monthly_store_goal,
            'is_active' => $seller->is_active,
            'stores_registered' => $seller->sold_companies_count ?? 0,
            'leads_assigned' => 0,
            'leads_converted' => 0,
            'commission_generated' => 0,
        ];
    }
}
