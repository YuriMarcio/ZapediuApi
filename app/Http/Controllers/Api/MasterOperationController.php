<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Operation;
use App\Models\User;
use App\Models\WhatsappSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MasterOperationController extends Controller
{
    public function index(): JsonResponse { return response()->json(['data' => Operation::with(['commercialManager:id,name,email', 'deliveryManager:id,name,email', 'whatsappSession'])->orderBy('name')->get()]); }

    public function context(Request $request): JsonResponse
    {
        $user = $request->user();
        $operations = Operation::query()->with('whatsappSession')
            ->when($user->role === 'manager', fn ($query) => $query->where('commercial_manager_id', $user->id))
            ->when($user->role === 'operator', fn ($query) => $query->where('delivery_manager_id', $user->id))
            ->when($user->role === 'seller', fn ($query) => $query->whereKey($user->operation_id))
            ->orderBy('name')->get();
        return response()->json(['data' => $operations]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required','string','max:255'], 'coverage_area' => ['required','string','max:255'], 'city' => ['required','string','max:120'], 'state' => ['required','string','size:2'], 'status' => ['sometimes', Rule::in(['active','inactive','implantation'])]]);
        $operation = Operation::create([...$data, 'state' => strtoupper($data['state'])]);
        return response()->json(['data' => $operation], 201);
    }

    public function update(Request $request, Operation $operation): JsonResponse
    {
        $data = $request->validate(['name' => ['sometimes','string','max:255'], 'coverage_area' => ['sometimes','string','max:255'], 'city' => ['sometimes','string','max:120'], 'state' => ['sometimes','string','size:2'], 'status' => ['sometimes', Rule::in(['active','inactive','implantation'])]]);
        if (isset($data['state'])) $data['state'] = strtoupper($data['state']);
        $operation->update($data);
        return response()->json(['data' => $operation->refresh()]);
    }

    public function destroy(Operation $operation): JsonResponse
    {
        $operation->delete();

        return response()->json(['message' => 'Operação removida com sucesso.']);
    }

    public function createManager(Request $request): JsonResponse
    {
        $data = $request->validate(['type' => ['required', Rule::in(['commercial','delivery'])], 'name' => ['required','string','max:255'], 'email' => ['required','email', Rule::unique('users','email')], 'phone' => ['nullable','string','max:30'], 'operation_ids' => ['required','array','min:1'], 'operation_ids.*' => ['integer', Rule::exists('operations','id')]]);
        $password = Str::password(12);
        $manager = User::create(['name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'] ?? null, 'password' => Hash::make($password), 'role' => $data['type'] === 'delivery' ? 'operator' : 'manager', 'is_active' => true]);
        $column = $data['type'] === 'delivery' ? 'delivery_manager_id' : 'commercial_manager_id';
        Operation::whereIn('id', $data['operation_ids'])->update([$column => $manager->id]);
        Mail::raw("Olá {$manager->name}, sua conta Zapediu foi criada. E-mail: {$manager->email}\nSenha temporária: {$password}\nAcesse: https://zapediu.com/login", fn ($message) => $message->to($manager->email)->subject('Sua conta Zapediu foi criada'));
        return response()->json(['data' => $manager->only(['id','name','email','phone','role'])], 201);
    }

    public function connectSession(Request $request, Operation $operation): JsonResponse
    {
        $data = $request->validate(['provider' => ['required', Rule::in(['evolution','zapi','meta'])], 'instance_mode' => ['nullable', Rule::in(['new','existing'])], 'instance_id' => ['nullable','string','max:120'], 'phone_number' => ['nullable','string','max:30'], 'credentials' => ['nullable','array']]);
        abort_if($operation->whatsappSession()->exists(), 422, 'A operação já possui uma sessão WhatsApp.');
        $instanceId = $data['instance_id'] ?: 'operation-'.$operation->id.'-'.Str::lower(Str::random(8));
        $secret = Str::random(48);
        $callbackUrl = rtrim((string) config('services.flowbridge.callback_base_url'), '/').'/api/webhooks/flowbridge/'.$secret;
        $body = ['provider' => $data['provider'], 'instanceId' => $instanceId, 'credentials' => $data['credentials'] ?? [], 'callbackUrl' => $callbackUrl];
        if ($data['provider'] === 'evolution' && ($data['instance_mode'] ?? 'new') === 'existing') $body['existing'] = true;
        $response = Http::baseUrl(rtrim((string) config('services.flowbridge.base_url'), '/'))->withHeaders(['x-api-key' => config('services.flowbridge.api_key')])->post('/v1/instances', $body)->throw()->json();
        $session = WhatsappSession::create(['operation_id' => $operation->id, 'provider' => $data['provider'], 'flowbridge_instance_id' => $instanceId, 'phone_number' => $data['phone_number'] ?? null, 'status' => data_get($response, 'connect.status') === 'connected' ? 'connected' : 'connecting', 'webhook_secret' => $secret]);
        return response()->json(['data' => $session, 'connect' => data_get($response, 'connect')], 201);
    }

    public function sessionQr(Operation $operation): JsonResponse { $session = $operation->whatsappSession; abort_unless($session, 404); return response()->json(Http::baseUrl(rtrim((string) config('services.flowbridge.base_url'), '/'))->withHeaders(['x-api-key' => config('services.flowbridge.api_key')])->get('/v1/instances/'.$session->flowbridge_instance_id.'/qrcode')->throw()->json()); }
    public function sessionStatus(Operation $operation): JsonResponse { $session = $operation->whatsappSession; abort_unless($session, 404); $status = Http::baseUrl(rtrim((string) config('services.flowbridge.base_url'), '/'))->withHeaders(['x-api-key' => config('services.flowbridge.api_key')])->get('/v1/instances/'.$session->flowbridge_instance_id)->throw()->json(); $session->update(['status' => data_get($status, 'state') === 'open' ? 'connected' : 'connecting']); return response()->json(['data' => $session->refresh(), 'provider_status' => $status]); }
}
