<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadNote;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LeadController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $leads = Lead::query()
            ->with(['assignedSeller:id,name', 'notes.user:id,name'])
            ->when($user->role === 'manager', fn ($query) => $query->where('manager_id', $user->id))
            ->when($user->role === 'seller', fn ($query) => $query->where('assigned_seller_id', $user->id))
            ->when($user->operation_id, fn ($query, $operationId) => $query->where('operation_id', $operationId))
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (Lead $lead) => $this->leadData($lead));

        return response()->json(['data' => $leads]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::in(Lead::CATEGORIES)],
            'zone' => ['nullable', 'string', 'max:255'],
            'stage' => ['nullable', Rule::in(Lead::STAGES)],
        ]);

        $assignedSellerId = $user->role === 'seller' ? $user->id : null;

        $lead = Lead::query()->create([
            ...$data,
            'manager_id' => $user->role === 'seller' ? $user->manager_id : $user->id,
            'operation_id' => $user->operation_id,
            'assigned_seller_id' => $assignedSellerId,
            'stage' => $data['stage'] ?? 'novo_lead',
            'last_contact_at' => now(),
        ]);

        return response()->json(['data' => $this->leadData($lead->load(['assignedSeller:id,name', 'notes.user:id,name']))], 201);
    }

    public function update(Request $request, Lead $lead): JsonResponse
    {
        $user = $request->user();
        $this->ensureAccess($user, $lead);
        $data = $request->validate([
            'stage' => ['sometimes', Rule::in(Lead::STAGES)],
            'loss_reason' => ['nullable', 'string'],
        ]);

        if (array_key_exists('stage', $data)) {
            $data['last_contact_at'] = now();
        }
        $lead->update($data);

        return response()->json(['data' => $this->leadData($lead->refresh()->load(['assignedSeller:id,name', 'notes.user:id,name']))]);
    }

    public function addNote(Request $request, Lead $lead): JsonResponse
    {
        $this->ensureAccess($request->user(), $lead);
        $data = $request->validate(['text' => ['required', 'string', 'max:4000']]);
        LeadNote::query()->create(['lead_id' => $lead->id, 'user_id' => $request->user()->id, 'text' => $data['text']]);
        $lead->update(['last_contact_at' => now()]);

        return response()->json(['data' => $this->leadData($lead->refresh()->load(['assignedSeller:id,name', 'notes.user:id,name']))]);
    }

    private function ensureAccess(User $user, Lead $lead): void
    {
        $hasAccess = $user->role === 'master'
            || ($user->role === 'manager' && $lead->manager_id === $user->id)
            || ($user->role === 'seller' && $lead->assigned_seller_id === $user->id);

        abort_unless($hasAccess, 404, 'Lead não encontrado.');
    }

    private function leadData(Lead $lead): array
    {
        return [
            'id' => $lead->id,
            'name' => $lead->name,
            'category' => $lead->category,
            'zone' => $lead->zone,
            'stage' => $lead->stage,
            'assigned_seller_id' => $lead->assigned_seller_id,
            'assigned_seller_name' => $lead->assignedSeller?->name,
            'loss_reason' => $lead->loss_reason,
            'last_contact_at' => $lead->last_contact_at?->toISOString(),
            'notes' => $lead->notes->map(fn (LeadNote $note) => ['id' => $note->id, 'author' => $note->user?->name ?? 'Sistema', 'text' => $note->text, 'created_at' => $note->created_at->toISOString()]),
        ];
    }
}
