<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Courier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Services\Zapi\ZapiClient;

class CourierController extends Controller
{
    public function store(Request $request, ZapiClient $zapiClient)
    {
        $data = $request->all();

        $validator = Validator::make($data, [
            'nome_completo' => 'required|string|max:255',
            'cpf' => 'required|string|max:14|unique:couriers,cpf',
            'email' => 'required|email|unique:couriers,email',
            'telefone' => 'required|string|max:20|unique:couriers,phone',
            'data_nascimento' => 'required|date',
            'veiculo.tipo' => 'required|string',
            'veiculo.placa' => 'required|string',
            'veiculo.cnh' => 'required|string|unique:couriers,cnh_number',
            'cidade_atuacao' => 'required|string',
            'estado_atuacao' => 'required|string|max:2',
            'dados_recebimento.tipo_chave_pix' => 'required|in:CPF,TELEFONE,EMAIL,ALEATORIA',
            'dados_recebimento.chave_pix' => 'required|string',
        ], [
            'cpf.unique' => 'Este CPF já está vinculado a outro entregador.',
            'email.unique' => 'Este email já está vinculado a outro entregador.',
            'telefone.unique' => 'Este telefone já está vinculado a outro entregador.',
            'veiculo.cnh.unique' => 'Esta CNH já está vinculada a outro entregador.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'sucesso' => false,
                'erro' => 'Erro de validação dos dados',
                'detalhes' => collect($validator->errors())->map(function($messages, $field) {
                    return [
                        'campo' => $field,
                        'mensagem' => $messages[0]
                    ];
                })->values(),
            ], 422);
        }

        $otp = random_int(100000, 999999);
        $otpKey = 'courier_otp_' . Str::uuid();
        Cache::put($otpKey, $otp, now()->addMinutes(10));

        DB::beginTransaction();
        try {
            $courier = Courier::create([
                'company_id' => $request->user()->company_id ?? 1,
                'first_name' => explode(' ', $data['nome_completo'])[0],
                'last_name' => collect(explode(' ', $data['nome_completo']))->slice(1)->implode(' '),
                'full_name' => $data['nome_completo'],
                'cpf' => $data['cpf'],
                'email' => $data['email'],
                'phone' => $data['telefone'],
                'birth_date' => $data['data_nascimento'],
                'vehicle_type' => $data['veiculo']['tipo'],
                'motorcycle_model' => $data['veiculo']['tipo'] === 'MOTO' ? ($data['veiculo']['modelo'] ?? null) : null,
                'license_plate' => $data['veiculo']['placa'],
                'cnh_number' => $data['veiculo']['cnh'],
                'city' => $data['cidade_atuacao'],
                'state' => $data['estado_atuacao'],
                'pix_key_type' => $data['dados_recebimento']['tipo_chave_pix'],
                'pix_key' => $data['dados_recebimento']['chave_pix'],
                'status' => 'AGUARDANDO_VERIFICACAO_WHATSAPP',
            ]);
            // Salva o OTP temporário atrelado ao entregador
            Cache::put('courier_otp_' . $courier->id, $otp, now()->addMinutes(10));
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'sucesso' => false,
                'erro' => 'Erro ao salvar entregador',
                'detalhes' => [$e->getMessage()]
            ], 500);
        }

        // Dispara WhatsApp
        try {
            $zapiClient->sendText(
                $courier->phone,
                "Olá {$courier->full_name}! Seu cadastro no delivery está quase pronto. Informe este código de 6 dígitos para o administrador concluir sua aprovação: {$otp}"
            );
        } catch (\Throwable $e) {
            // Logar erro, mas não impedir o fluxo
        }

        return response()->json([
            'sucesso' => true,
            'mensagem' => 'Pré-cadastro realizado. Um código de verificação foi enviado para o WhatsApp do entregador.',
            'dados' => [
                'entregador_id' => $courier->id,
                'status_cadastro' => 'AGUARDANDO_VERIFICACAO_WHATSAPP',
            ]
        ], 201);
    }
}
