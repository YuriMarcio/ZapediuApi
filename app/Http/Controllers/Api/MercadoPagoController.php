<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use App\Models\Wallet;
// Import the V3 SDK classes
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Exceptions\MPApiException;
use App\Models\Order;
class MercadoPagoController extends Controller
{
// =========================================================================
    // 2. GERAÇÃO DE PIX: Usa a carteira da loja para receber o dinheiro
    // =========================================================================
    public function createPix(Request $request)
    {

        $data = $request->validate([
            'code' => 'required|string',
            'token' => 'required|string',
            'cpf' => 'nullable|string',
        ]);

        $order = Order::with('user')->where('code', $data['code'])->first();

        Log::info('order encotrada', [
            'order' => $order
        ]);

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

        Log::info('Criando pagamento PIX certo', [
            'order_code' => $order->code,
            'order_total' => $order->total,
            'customer_email' => $order->user?->email,
        ]);

        // ---------------------------------------------------------------------
        // 🚀 A MÁGICA MULTI-LOJA ACONTECE AQUI
        // ---------------------------------------------------------------------
        // Pegamos a carteira baseada no company_id do pedido
        // (Se o seu pedido não tiver company_id direto, use $order->store->company_id)
         Log::info('company_id qual é ?', [
            'company_id' => $order->company_id
        ]);

        $wallet = Wallet::where('company_id', $order->store_id)->first();

        Log::info("wallet da company",
        [
            "wallet" => $wallet
        ]);

        if (!$wallet || !$wallet->is_active || empty($wallet->mp_access_token)) {
            Log::warning('Tentativa de PIX em loja sem carteira ativa', ['order_code' => $order->code]);
            return response()->json(['message' => 'Esta loja ainda não configurou ou ativou o Mercado Pago.'], 400);
        }

        Log::info('Carteira encontrada para a loja, preparando SDK do Mercado Pago', [
            'company_id' => $wallet->company_id,
            'mp_user_id' => $wallet->mp_user_id,
            'mp_access_token_set' => $wallet->mp_access_token
        ]);

        // Configura o Mercado Pago com o TOKEN DA LOJA (e não o do seu .env)
        MercadoPagoConfig::setAccessToken($wallet->mp_access_token);

        Log::info('Access token da LOJA configurado para o PIX', [
            'company_id' => $wallet->company_id
        ]);
        // ---------------------------------------------------------------------

        $amount = (float) $order->total;
        $description = 'Pedido #' . $order->code . ' - ' . config('app.name');
        $name = $order->user?->name ?? 'Cliente Padrão';
        $email = $order->user?->email ?? 'cliente@example.com';
        
        $cpf = preg_replace('/[^0-9]/', '', $data['cpf'] ?? $order->user?->cpf ?? '12345678909');
        if (empty($cpf)) {
            $cpf = '12345678909';
        }

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
                Log::error('Erro ao gerar PIX no Mercado Pago (Sem transaction_data)', [
                    'payment' => $payment
                ]);
                return response()->json(['error' => 'Erro ao gerar cobrança PIX no Mercado Pago.'], 500);
            }

            return response()->json([
                'qr_code_base64' => $payment->point_of_interaction->transaction_data->qr_code_base64 ?? null,
                'qr_code' => $payment->point_of_interaction->transaction_data->qr_code ?? null,
            ]);

        } catch (MPApiException $e) {
            Log::error('Exceção ao gerar PIX no Mercado Pago', [
                'error' => $e->getMessage(),
                'response' => $e->getApiResponse()->getContent()
            ]);
            return response()->json(['error' => 'Falha na comunicação com o Mercado Pago.'], 500);
        }
    }

    // =========================================================================
    // 3. GERAÇÃO DE CARTÃO: Usa a carteira da loja para debitar o cartão
    // =========================================================================
    public function createCardPayment(Request $request)
    {
        Log::info('Iniciando pagamento por Cartão de Crédito', [
            'order_code' => $request->input('code')
        ]);

        // 1. Validações (Note que agora exigimos o token do cartão e parcelas)
        $data = $request->validate([
            'code'              => 'required|string',
            'token'             => 'required|string', // Token público do seu checkout
            'card_token'        => 'required|string', // Token gerado pelo MercadoPago.js no frontend
            'installments'      => 'required|integer|min:1',
            'payment_method_id' => 'required|string', // Ex: 'visa', 'master'
            'issuer_id'         => 'nullable|string', // ID do banco emissor (opcional)
            'cpf'               => 'nullable|string',
            'email'             => 'nullable|email',
        ]);

        $order = Order::with('user')->where('code', $data['code'])->first();

        if (!$order) {
            return response()->json(['message' => 'Pedido não encontrado.'], 404);
        }

        // Validate public token (Sua segurança do checkout)
        $expectedToken = (string) data_get($order->raw_payload, 'checkout.public_token', '');
        if ($expectedToken === '' || !hash_equals($expectedToken, $data['token'])) {
            return response()->json(['message' => 'Token de checkout inválido.'], 403);
        }

        if (in_array($order->payment_status, ['paid', 'approved'], true)) {
            return response()->json(['message' => 'Este pedido já possui pagamento confirmado.'], 409);
        }

        // 2. A MÁGICA MULTI-LOJA: Pegar a carteira do Lojista
        $wallet = Wallet::where('company_id', $order->company_id)->first();

        if (!$wallet || !$wallet->is_active || empty($wallet->mp_access_token)) {
            Log::warning('Tentativa de Cartão em loja sem carteira ativa', ['order_code' => $order->code]);
            return response()->json(['message' => 'Esta loja ainda não configurou o Mercado Pago.'], 400);
        }

        // 3. Injetar o Token da LOJA no SDK do Mercado Pago
        MercadoPagoConfig::setAccessToken($wallet->mp_access_token);

        $amount = (float) $order->total;
        $description = 'Pedido #' . $order->code . ' - ' . config('app.name');
        $email = $data['email'] ?? $order->user?->email ?? 'cliente@example.com';
        
        $cpf = preg_replace('/[^0-9]/', '', $data['cpf'] ?? $order->user?->cpf ?? '12345678909');
        if (empty($cpf)) {
            $cpf = '12345678909';
        }

        $client = new PaymentClient();

        try {
            // 4. Disparar a cobrança no cartão
            $payment = $client->create([
                "transaction_amount" => $amount,
                "token"              => $data['card_token'], // O token seguro do plástico
                "description"        => $description,
                "installments"       => (int) $data['installments'],
                "payment_method_id"  => $data['payment_method_id'],
                "issuer_id"          => $data['issuer_id'] ?? null,
                "payer" => [
                    "email" => $email,
                    "identification" => [
                        "type" => "CPF",
                        "number" => $cpf,
                    ],
                ]
            ]);

            Log::info('Resposta do MP para o Cartão', [
                'status' => $payment->status,
                'status_detail' => $payment->status_detail
            ]);

            // 5. Tratar a resposta
            // Cartão pode ser aprovado na hora, rejeitado ou ir para análise (in_process)
            if ($payment->status === 'approved') {
                // Aqui você pode atualizar o status do pedido no seu banco de dados
                // $order->update(['payment_status' => 'paid']);
                
                return response()->json([
                    'status' => 'approved',
                    'message' => 'Pagamento aprovado com sucesso!',
                    'payment_id' => $payment->id
                ]);
            } elseif ($payment->status === 'in_process') {
                return response()->json([
                    'status' => 'in_process',
                    'message' => 'Pagamento em análise.',
                    'payment_id' => $payment->id
                ]);
            } else {
                // Rejeitado (falta de limite, bloqueio anti-fraude, etc)
                return response()->json([
                    'status' => 'rejected',
                    'error' => 'Pagamento recusado.',
                    'detail' => $payment->status_detail // Ex: cc_rejected_insufficient_amount
                ], 400);
            }

        } catch (MPApiException $e) {
            Log::error('Exceção ao processar Cartão no Mercado Pago', [
                'error' => $e->getMessage(),
                'response' => $e->getApiResponse()->getContent()
            ]);
            return response()->json(['error' => 'Falha na comunicação com o Mercado Pago.'], 500);
        }
    }


    public function handleCallback(Request $request)
    {
        // 1. Validar se o MP enviou o 'code' e o 'state' (ID da Empresa)
        $code = $request->query('code');

        Log::info('Callback Mercado Pago recebido', [
            'code' => $code,
            'state' => $request->query('state'),
        ]);

        $companyId = $request->query('state');

        if (!$code || !$companyId) {
            return response()->json(['error' => 'Autorização inválida'], 400);
        }

        // 2. Trocar o CODE pelo Access Token (Server-to-Server)
        $response = Http::asForm()->post('https://api.mercadopago.com/oauth/token', [
            'client_id' => config('services.mercadopago.client_id'),
            'client_secret' => config('services.mercadopago.client_secret'),
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => config('services.mercadopago.redirect_uri'),
        ]);


        if ($response->failed()) {
            return response()->json([
                'error' => 'Falha ao obter token', 
                'mp_motivo' => $response->json() // Isso vai te dizer exatamente o que você errou!
            ], 500);
        }

        $data = $response->json();

        Log::info('response mp',[
            'data' => $data
        ]);


        // 3. Salvar na Wallet da Company
        $wallet = Wallet::where('company_id', $companyId)->first();

        Log::info('Atualizando carteira com dados do Mercado Pago', [
            'company_id' => $companyId,
            'wallet_found' => (bool) $wallet,
        ]);

        if ($wallet) {
            $wallet->update([
                'mp_access_token'  => $data['access_token'],
                'mp_refresh_token' => $data['refresh_token'],
                'mp_public_key'    => $data['public_key'],
                'mp_user_id'       => $data['user_id'],
                'mp_expires_at'    => now()->addSeconds($data['expires_in']),
                'is_active'        => true
            ]);
        }

        // 4. Redirecionar para o seu Painel Frontend
        // Substitua pela URL do seu dashboard
        return redirect()->away("https://painel.zapediu.com.br/painel/Carteira");
    }

}
