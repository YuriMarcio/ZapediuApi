<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Models\DeliveryGroup;
use App\Models\Operation;
use App\Models\Order;
use App\Services\Whatsapp\CourierConfirmationService;
use App\Services\Whatsapp\WhatsAppClientInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class DeliveryManagementController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        $operation = $this->operation($request);
        if ($operation === null) {
            return response()->json(['data' => [
                'active_couriers' => 0,
                'pending_confirmation' => 0,
                'active_groups' => 0,
                'monthly_commission' => 0.0,
            ]]);
        }
        $couriers = Courier::query()->where('operation_id', $operation->id);
        $monthStart = now()->startOfMonth();

        return response()->json(['data' => [
            'active_couriers' => (clone $couriers)->where('registration_status', 'confirmed')->count(),
            'pending_confirmation' => (clone $couriers)->where('registration_status', 'pending_confirmation')->count(),
            'active_groups' => DeliveryGroup::query()->where('operation_id', $operation->id)->where('is_active', true)->count(),
            'monthly_commission' => (float) Order::query()
                ->where('operation_id', $operation->id)
                ->whereNotNull('courier_id')
                ->where('created_at', '>=', $monthStart)
                ->sum('delivery_fee'),
        ]]);
    }

    public function couriers(Request $request): JsonResponse
    {
        $operation = $this->operation($request);
        if ($operation === null) {
            return response()->json(['data' => []]);
        }
        $monthStart = now()->startOfMonth();

        $couriers = Courier::query()
            ->with('deliveryGroup:id,name')
            ->withSum(['orders as monthly_commission' => fn ($query) => $query->where('created_at', '>=', $monthStart)], 'delivery_fee')
            ->where('operation_id', $operation->id)
            ->latest()
            ->get();

        return response()->json(['data' => $couriers->map(fn (Courier $courier) => $this->courierData($courier))->values()]);
    }

    public function storeCourier(Request $request, CourierConfirmationService $confirmation, WhatsAppClientInterface $whatsapp): JsonResponse
    {
        $operation = $this->requireOperation($request);
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30', Rule::unique('couriers', 'phone')],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('couriers', 'email')],
            'cpf' => ['required', 'string', 'max:14', Rule::unique('couriers', 'cpf')],
            'vehicle_type' => ['required', Rule::in(['bike', 'moto', 'carro'])],
            'license_plate' => ['nullable', 'string', 'max:30'],
            'document_type' => ['nullable', 'string', 'max:60'],
            'document_number' => ['nullable', 'string', 'max:100'],
            'delivery_group_id' => ['required', Rule::exists('delivery_groups', 'id')],
            'profile_image' => ['nullable', 'image', 'max:5120'],
            'driver_license' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ]);

        $group = DeliveryGroup::query()
            ->where('operation_id', $operation->id)
            ->whereKey($data['delivery_group_id'])
            ->firstOrFail();

        if (in_array($data['vehicle_type'], ['moto', 'carro'], true) && ! $request->hasFile('driver_license')) {
            return response()->json(['message' => 'A foto da carteira de motorista é obrigatória para moto ou carro.', 'errors' => ['driver_license' => ['Envie a foto da carteira de motorista.']]], 422);
        }

        $nameParts = preg_split('/\s+/', trim($data['full_name'])) ?: [];
        $courier = Courier::query()->create([
            'operation_id' => $operation->id,
            'delivery_group_id' => $group->id,
            'first_name' => $nameParts[0] ?? $data['full_name'],
            'last_name' => implode(' ', array_slice($nameParts, 1)),
            'full_name' => $data['full_name'],
            'phone' => $data['phone'],
            'email' => $data['email'] ?? null,
            'cpf' => $data['cpf'],
            'vehicle_type' => $data['vehicle_type'],
            'license_plate' => $data['license_plate'] ?? null,
            'cnh_number' => $data['document_number'] ?? null,
            'status' => 'inativo',
            'registration_status' => 'pending_confirmation',
            'profile_image_path' => $request->file('profile_image')?->store('couriers/profiles', 'public'),
            'driver_license_path' => $request->file('driver_license')?->store('couriers/licenses', 'public'),
        ])->load('deliveryGroup:id,name');

        $confirmation->send($courier, $whatsapp);

        return response()->json(['data' => $this->courierData($courier), 'message' => 'Cadastro salvo como inativo. A confirmação foi enviada ao WhatsApp do entregador.'], 201);
    }

    public function updateCourier(Request $request, Courier $courier): JsonResponse
    {
        $operation = $this->requireOperation($request);
        abort_unless($courier->operation_id === $operation->id, 404);
        $data = $request->validate([
            'delivery_group_id' => ['nullable', Rule::exists('delivery_groups', 'id')],
            'status' => ['nullable', Rule::in(['inativo', 'disponivel'])],
        ]);

        if (isset($data['delivery_group_id'])) {
            DeliveryGroup::query()->where('operation_id', $operation->id)->whereKey($data['delivery_group_id'])->firstOrFail();
        }
        if (($data['status'] ?? null) === 'disponivel' && $courier->registration_status !== 'confirmed') {
            return response()->json(['message' => 'O entregador só pode ser ativado após confirmar o cadastro pelo WhatsApp.'], 422);
        }

        $courier->update($data);
        return response()->json(['data' => $this->courierData($courier->refresh()->load('deliveryGroup:id,name'))]);
    }

    public function resendConfirmation(Request $request, Courier $courier, CourierConfirmationService $confirmation, WhatsAppClientInterface $whatsapp): JsonResponse
    {
        $operation = $this->requireOperation($request);
        abort_unless($courier->operation_id === $operation->id, 404);
        abort_if($courier->registration_status === 'confirmed', 422, 'Este entregador já confirmou o cadastro.');

        $confirmation->send($courier->load('deliveryGroup'), $whatsapp);
        return response()->json(['message' => 'Mensagem de confirmação reenviada.']);
    }

    public function groups(Request $request): JsonResponse
    {
        $operation = $this->operation($request);
        if ($operation === null) {
            return response()->json(['data' => []]);
        }
        $groups = DeliveryGroup::query()
            ->withCount(['couriers as couriers_count' => fn ($query) => $query->where('registration_status', 'confirmed')])
            ->where('operation_id', $operation->id)
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $groups->map(fn (DeliveryGroup $group) => $this->groupData($group))->values()]);
    }

    public function storeGroup(Request $request): JsonResponse
    {
        $operation = $this->requireOperation($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'whatsapp_group_jid' => ['required', 'string', 'max:160', Rule::unique('delivery_groups', 'whatsapp_group_jid')],
            'invite_url' => ['nullable', 'url', 'max:500'],
        ]);
        $group = DeliveryGroup::query()->create([...$data, 'operation_id' => $operation->id]);
        return response()->json(['data' => $this->groupData($group)], 201);
    }

    private function operation(Request $request): ?Operation
    {
        $user = $request->user();
        $operationId = $request->integer('operation_id');
        $operations = Operation::query();

        if ($user->role === 'operator') {
            $operations->where('delivery_manager_id', $user->id);
        }
        if ($operationId > 0) {
            return $operations->find($operationId);
        }

        return $operations->orderBy('name')->first();
    }

    private function requireOperation(Request $request): Operation
    {
        $operation = $this->operation($request);

        abort_if($operation === null, 422, 'Nenhuma operação de entrega está vinculada ao seu acesso.');

        return $operation;
    }

    private function courierData(Courier $courier): array
    {
        return [
            'id' => $courier->id,
            'name' => $courier->full_name,
            'phone' => $courier->phone,
            'email' => $courier->email,
            'vehicle_type' => $courier->vehicle_type,
            'license_plate' => $courier->license_plate,
            'status' => $courier->status,
            'registration_status' => $courier->registration_status,
            'confirmed_at' => $courier->confirmed_at?->toISOString(),
            'delivery_group' => $courier->deliveryGroup ? ['id' => $courier->deliveryGroup->id, 'name' => $courier->deliveryGroup->name] : null,
            'profile_image_url' => $courier->profile_image_path ? Storage::disk('public')->url($courier->profile_image_path) : null,
            'monthly_commission' => (float) ($courier->monthly_commission ?? 0),
        ];
    }

    private function groupData(DeliveryGroup $group): array
    {
        return [
            'id' => $group->id,
            'name' => $group->name,
            'whatsapp_group_jid' => $group->whatsapp_group_jid,
            'invite_url' => $group->invite_url,
            'is_active' => $group->is_active,
            'couriers_count' => $group->couriers_count ?? 0,
        ];
    }
}
