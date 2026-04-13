<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;

class MercadoPagoController extends Controller
{
    public function createPix(Request $request)
    {
        $data = $request->validate([
            'amount' => 'required|numeric',
            'description' => 'required|string',
            'payer.name' => 'required|string',
            'payer.email' => 'required|email',
            'payer.identification' => 'required|string',
        ]);

        // Integração real Mercado Pago
        $accessToken = config('mercadopago.access_token');
        \MercadoPago\SDK::setAccessToken($accessToken);

        $payment = new \MercadoPago\Point\Payment();
        $payment->transaction_amount = $data['amount'];
        $payment->description = $data['description'];
        $payment->payment_method_id = 'pix';
        $payment->payer = [
            'email' => $data['payer']['email'],
            'first_name' => $data['payer']['name'],
            'identification' => [
                'type' => 'CPF',
                'number' => $data['payer']['identification'],
            ],
        ];
        $payment->save();

        if (!isset($payment->point_of_interaction->transaction_data)) {
            return response()->json(['error' => 'Erro ao gerar cobrança PIX'], 500);
        }

        return response()->json([
            'qr_code_base64' => $payment->point_of_interaction->transaction_data->qr_code_base64,
            'qr_code' => $payment->point_of_interaction->transaction_data->qr_code,
        ]);
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
