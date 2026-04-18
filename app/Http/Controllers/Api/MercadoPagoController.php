<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;
// Import the V3 SDK classes
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Exceptions\MPApiException;

class MercadoPagoController extends Controller
{
    public function createPix(Request $request)
    {
        logger()->info('Criando pagamento PIX', [
            'request_data' => $request->all(),
        ]);

        $data = $request->validate([
            'code' => 'required|string',
            'token' => 'required|string',
            'cpf' => 'nullable|string',
        ]);

        $order = \App\Models\Order::with('user')->where('code', $data['code'])->first();

        if (!$order) {
            return response()->json(['message' => 'Pedido não encontrado.'], 404);
        }

        // Validate token
        $expectedToken = (string) data_get($order->raw_payload, 'checkout.public_token', '');
        if ($expectedToken === '' || !hash_equals($expectedToken, $data['token'])) {
            return response()->json(['message' => 'Token de checkout inválido.'], 403);
        }

        // Check if already paid
        if (in_array($order->payment_status, ['paid', 'approved'], true)) {
            return response()->json(['message' => 'Este pedido já possui pagamento confirmado.'], 409);
        }

        // Extract or fallback payer info
        $amount = (float) $order->total;
        $description = 'Pedido #' . $order->code . ' - ' . config('app.name');
        $name = $order->user?->name ?? 'Cliente Padrão';
        $email = $order->user?->email ?? 'cliente@example.com';
        
        // Use provided CPF, or the user's CPF, or a placeholder if testing
        $cpf = preg_replace('/[^0-9]/', '', $data['cpf'] ?? $order->user?->cpf ?? '12345678909');
        if (empty($cpf)) {
            $cpf = '12345678909';
        }

        // Integração real Mercado Pago V3
        $accessToken = config('mercadopago.access_token');
        MercadoPagoConfig::setAccessToken($accessToken);

        logger()->info('Access token configurado para Mercado Pago', [
            'access_token_set' => !empty($accessToken),
        ]);

        $client = new PaymentClient();

        try {
            $payment = $client->create([
                "transaction_amount" => $amount,
                "description" => $description,
                "payment_method_id" => "pix",
                "payer" => [
                    "email" => $email,
                    "first_name" => $name,
                    "identification" => [
                        "type" => "CPF",
                        "number" => $cpf,
                    ],
                ]
            ]);

            if (!isset($payment->point_of_interaction->transaction_data)) {
                logger()->error('Erro ao gerar PIX no Mercado Pago (Sem transaction_data)', [
                    'payment' => $payment
                ]);
                return response()->json(['error' => 'Erro ao gerar cobrança PIX no Mercado Pago.'], 500);
            }

            return response()->json([
                'qr_code_base64' => $payment->point_of_interaction->transaction_data->qr_code_base64 ?? null,
                'qr_code' => $payment->point_of_interaction->transaction_data->qr_code ?? null,
            ]);

        } catch (MPApiException $e) {
            logger()->error('Exceção ao gerar PIX no Mercado Pago', [
                'error' => $e->getMessage(),
                'response' => $e->getApiResponse()->getContent()
            ]);
            return response()->json(['error' => 'Falha na comunicação com o Mercado Pago.'], 500);
        }
    }

    public function payCard(Request $request)
    {
        $data = $request->validate([
            'token' => 'required|string',
            'amount' => 'required|numeric',
            'description' => 'required|string',
            'payer.name' => 'required|string',
            'payer.email' => 'required|email',
            'payer.identification' => 'required|string',
        ]);

        $accessToken = config('mercadopago.access_token');
        \MercadoPago\SDK::setAccessToken($accessToken);

        $payment = new \MercadoPago\Payment();
        $payment->transaction_amount = $data['amount'];
        $payment->token = $data['token'];
        $payment->description = $data['description'];
        $payment->installments = 1;
        $payment->payment_method_id = 'visa'; // ou dinâmico
        $payment->payer = [
            'email' => $data['payer']['email'],
            'first_name' => $data['payer']['name'],
            'identification' => [
                'type' => 'CPF',
                'number' => $data['payer']['identification'],
            ],
        ];
        $payment->save();

        if ($payment->status === 'approved') {
            return response()->json([
                'status' => 'approved',
                'payment_id' => $payment->id,
            ]);
        }
        return response()->json([
            'status' => 'rejected',
            'error' => $payment->status_detail,
        ], 400);
    }
}
