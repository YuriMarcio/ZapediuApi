<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\OnboardingStoreRequest;
use App\Models\User;
use App\Services\Onboarding\OnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class OnboardingController extends Controller
{
    public function __construct(private readonly OnboardingService $service) {}

    public function validateSellerCode(Request $request): JsonResponse
    {
        $request->validate([
            'seller_code' => ['required', 'string'],
        ]);

        $seller = User::query()
            ->where('seller_code', (string) $request->input('seller_code'))
            ->where('role', 'seller')
            ->first();

        if ($seller === null) {
            return response()->json([
                'message' => 'Código do vendedor inválido.',
                'errors'  => [
                    'seller_code' => ['Código não encontrado.'],
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'valid'  => true,
            'seller' => [
                'id'   => $seller->id,
                'name' => $seller->name,
                'code' => $seller->seller_code,
            ],
        ]);
    }

    public function store(OnboardingStoreRequest $request): JsonResponse
    {
        $result = $this->service->create($request->validated());

        return response()->json($result, Response::HTTP_CREATED);
    }

    public function metadata(): JsonResponse
    {
        return response()->json([
            'default_timezone' => 'America/Sao_Paulo',
            'supported_states' => [
                'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES',
                'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR',
                'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC',
                'SP', 'SE', 'TO',
            ],
        ]);
    }
}
