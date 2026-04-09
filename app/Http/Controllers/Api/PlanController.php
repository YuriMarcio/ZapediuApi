<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class PlanController extends Controller
{
    /** Public listing — used by the onboarding / plan picker screen. */
    public function index(): JsonResponse
    {
        $plans = Plan::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['slug', 'name', 'tagline', 'pitch', 'fee_percent', 'fee_fixed', 'features']);

        return response()->json($plans);
    }

    // ──────────────────────────────────────────────
    // Admin CRUD (requires X-Admin-Token header)
    // ──────────────────────────────────────────────

    public function adminIndex(Request $request): JsonResponse
    {
        if (! $this->authorized($request)) {
            return response()->json(['message' => 'Unauthorized.'], Response::HTTP_UNAUTHORIZED);
        }

        return response()->json(Plan::query()->orderBy('sort_order')->get());
    }

    public function adminStore(Request $request): JsonResponse
    {
        if (! $this->authorized($request)) {
            return response()->json(['message' => 'Unauthorized.'], Response::HTTP_UNAUTHORIZED);
        }

        $data = $request->validate([
            'slug'        => ['required', 'string', 'max:60', Rule::unique('plans', 'slug')],
            'name'        => ['required', 'string', 'max:120'],
            'tagline'     => ['nullable', 'string', 'max:255'],
            'pitch'       => ['nullable', 'string'],
            'fee_percent' => ['required', 'numeric', 'min:0'],
            'fee_fixed'   => ['required', 'numeric', 'min:0'],
            'features'    => ['nullable', 'array'],
            'features.*'  => ['string'],
            'is_active'   => ['boolean'],
            'sort_order'  => ['integer', 'min:0'],
        ]);

        $plan = Plan::query()->create($data);

        return response()->json($plan, Response::HTTP_CREATED);
    }

    public function adminUpdate(Request $request, Plan $plan): JsonResponse
    {
        if (! $this->authorized($request)) {
            return response()->json(['message' => 'Unauthorized.'], Response::HTTP_UNAUTHORIZED);
        }

        $data = $request->validate([
            'slug'        => ['sometimes', 'string', 'max:60', Rule::unique('plans', 'slug')->ignore($plan->id)],
            'name'        => ['sometimes', 'string', 'max:120'],
            'tagline'     => ['nullable', 'string', 'max:255'],
            'pitch'       => ['nullable', 'string'],
            'fee_percent' => ['sometimes', 'numeric', 'min:0'],
            'fee_fixed'   => ['sometimes', 'numeric', 'min:0'],
            'features'    => ['nullable', 'array'],
            'features.*'  => ['string'],
            'is_active'   => ['boolean'],
            'sort_order'  => ['integer', 'min:0'],
        ]);

        $plan->update($data);

        return response()->json($plan);
    }

    public function adminDestroy(Request $request, Plan $plan): JsonResponse
    {
        if (! $this->authorized($request)) {
            return response()->json(['message' => 'Unauthorized.'], Response::HTTP_UNAUTHORIZED);
        }

        $plan->delete();

        return response()->json(['message' => 'Plano removido.']);
    }

    private function authorized(Request $request): bool
    {
        $token = config('auth.master_password');

        return $token !== '' && $request->header('X-Admin-Token') === $token;
    }
}
