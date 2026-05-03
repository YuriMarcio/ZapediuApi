<?php

namespace App\Services\Payment;

use App\Models\Order;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Exceptions\MPApiException;

class MercadoPagoPaymentService
{
    /**
     * Valida a assinatura do webhook do Mercado Pago
     *
     * @param Request $request
     * @return bool
     */
    public function validateWebhookSignature(Request $request): bool
    {
        $signature = $request->header('x-signature');
        
        if (empty($signature)) {
            Log::warning('MercadoPago Webhook: Missing x-signature header');
            return false;
        }

        // Formato: ts=1234567890,v1=abc123def456...
        $parts = explode(',', $signature);
        
        if (count($parts) !== 2) {
            Log::warning('MercadoPago Webhook: Invalid signature format', ['signature' => $signature]);
            return false;
        }

        $ts = str_replace('ts=', '', $parts[0]);
        $hash = str_replace('v1=', '', $parts[1]);

        // Verificar timestamp (rejeitar se > 5 minutos)
        $timestamp = (int) $ts;
        $now = time();
        
        if (abs($now - $timestamp) > 300) { // 5 minutos
            Log::warning('MercadoPago Webhook: Timestamp too old or in future', [
                'timestamp' => $timestamp,
                'now' => $now,
                'diff' => abs($now - $timestamp)
            ]);
            return false;
        }

        // Verificar se já processamos este request-id (replay attack)
        $requestId = $request->header('x-request-id');
        if ($requestId && Cache::has('mp_webhook_processed:' . $requestId)) {
            Log::warning('MercadoPago Webhook: Duplicate request detected', ['request_id' => $requestId]);
            return false;
        }

        // Validar assinatura
        $paymentId = $request->input('data.id');
        $manifest = "id:{$paymentId};request-id:{$requestId};ts:{$ts};";
        $secret = config('services.mercadopago.webhook_secret');

        if (empty($secret)) {
            Log::error('MercadoPago Webhook: Webhook secret not configured');
            return false;
        }

        $expectedHash = hash_hmac('sha256', $manifest, $secret);

        if (!hash_equals($expectedHash, $hash)) {
            Log::warning('MercadoPago Webhook: Invalid signature hash', [
                'expected' => $expectedHash,
                'received' => $hash
            ]);
            return false;
        }

        // Marcar request-id como processado (cache por 15 minutos)
        if ($requestId) {
            Cache::put('mp_webhook_processed:' . $requestId, true, 900);
        }

        return true;
    }

    /**
     * Busca detalhes do pagamento via API do Mercado Pago
     *
     * @param string $paymentId
     * @param string $accessToken
     * @return array|null
     */
    public function getPaymentDetails(string $paymentId, string $accessToken): ?array
    {
        try {
            // Configurar SDK com o token da loja
            MercadoPagoConfig::setAccessToken($accessToken);
            
            $client = new PaymentClient();
            $payment = $client->get($paymentId);

            return [
                'id' => $payment->id,
                'status' => $payment->status,
                'status_detail' => $payment->status_detail,
                'transaction_amount' => $payment->transaction_amount,
                'currency_id' => $payment->currency_id,
                'description' => $payment->description,
                'payment_method_id' => $payment->payment_method_id,
                'payment_type_id' => $payment->payment_type_id,
                'date_approved' => $payment->date_approved,
                'date_created' => $payment->date_created,
                'date_last_updated' => $payment->date_last_updated,
                'payer' => [
                    'email' => $payment->payer->email ?? null,
                    'first_name' => $payment->payer->first_name ?? null,
                    'last_name' => $payment->payer->last_name ?? null,
                    'identification' => [
                        'type' => $payment->payer->identification->type ?? null,
                        'number' => $payment->payer->identification->number ?? null,
                    ],
                ],
                'metadata' => $payment->metadata ?? [],
                'external_reference' => $payment->external_reference ?? null,
                'additional_info' => $payment->additional_info ?? null,
                'point_of_interaction' => $payment->point_of_interaction ?? null,
            ];
        } catch (MPApiException $e) {
            Log::error('MercadoPago API Error', [
                'payment_id' => $paymentId,
                'error' => $e->getMessage(),
                'response' => $e->getApiResponse()->getContent()
            ]);
            return null;
        } catch (\Exception $e) {
            Log::error('MercadoPago Service Error', [
                'payment_id' => $paymentId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Extrai o código do pedido do pagamento
     *
     * @param array $payment
     * @return string|null
     */
    public function extractOrderCodeFromPayment(array $payment): ?string
    {
        // Tentar extrair de external_reference (formato: order_{code})
        if (!empty($payment['external_reference'])) {
            $externalRef = $payment['external_reference'];
            
            // Se começar com "order_", extrair o código
            if (str_starts_with($externalRef, 'order_')) {
                return str_replace('order_', '', $externalRef);
            }
            
            // Se for apenas o código
            return $externalRef;
        }

        // Tentar extrair de metadata
        if (!empty($payment['metadata']['order_code'])) {
            return $payment['metadata']['order_code'];
        }

        // Tentar extrair de description (formato: Pedido #{code})
        if (!empty($payment['description'])) {
            $description = $payment['description'];
            if (preg_match('/Pedido\s*#?\s*([A-Z0-9]+)/i', $description, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * Determina qual wallet (loja) processou o pagamento
     *
     * @param array $payment
     * @return Wallet|null
     */
    public function findWalletForPayment(array $payment): ?Wallet
    {
        // Tentar encontrar pelo user_id do Mercado Pago
        $mpUserId = $payment['payer']['id'] ?? null;
        
        if ($mpUserId) {
            $wallet = Wallet::where('mp_user_id', $mpUserId)
                ->where('is_active', true)
                ->first();
                
            if ($wallet) {
                return $wallet;
            }
        }

        // Tentar encontrar pelo external_reference que pode conter company_id
        if (!empty($payment['external_reference'])) {
            $externalRef = $payment['external_reference'];
            
            // Formato: order_{code}_{company_id}
            if (preg_match('/order_([A-Z0-9]+)_(\d+)/', $externalRef, $matches)) {
                $companyId = $matches[2];
                
                $wallet = Wallet::where('company_id', $companyId)
                    ->where('is_active', true)
                    ->first();
                    
                if ($wallet) {
                    return $wallet;
                }
            }
        }

        // Se não encontrou, tentar buscar pelo pedido
        $orderCode = $this->extractOrderCodeFromPayment($payment);
        
        if ($orderCode) {
            $order = Order::where('code', $orderCode)->first();
            
            if ($order && $order->company_id) {
                $wallet = Wallet::where('company_id', $order->company_id)
                    ->where('is_active', true)
                    ->first();
                    
                if ($wallet) {
                    return $wallet;
                }
            }
        }

        return null;
    }

    /**
     * Processa uma notificação de webhook do Mercado Pago
     *
     * @param Request $request
     * @return array
     */
    public function processWebhook(Request $request): array
    {
        $type = $request->input('type');
        $action = $request->input('action');
        $paymentId = $request->input('data.id');

        Log::info('MercadoPago Webhook Received', [
            'type' => $type,
            'action' => $action,
            'payment_id' => $paymentId,
            'request_id' => $request->header('x-request-id')
        ]);

        // Só processamos notificações de pagamento
        if ($type !== 'payment') {
            Log::info('MercadoPago Webhook: Ignoring non-payment notification', ['type' => $type]);
            return ['processed' => false, 'reason' => 'Non-payment notification'];
        }

        // Buscar detalhes do pagamento
        $wallet = null;
        $paymentDetails = null;
        
        // Primeiro, tentar encontrar a wallet para obter o access_token
        // Para isso, precisamos buscar o pagamento com o token global inicial
        try {
            // Usar token global para buscar detalhes iniciais
            $globalToken = config('services.mercadopago.access_token');
            
            if ($globalToken) {
                MercadoPagoConfig::setAccessToken($globalToken);
                $client = new PaymentClient();
                $initialPayment = $client->get($paymentId);
                
                // Converter para array para processamento
                $paymentDetails = [
                    'id' => $initialPayment->id,
                    'status' => $initialPayment->status,
                    'payer' => [
                        'id' => $initialPayment->payer->id ?? null,
                    ],
                    'external_reference' => $initialPayment->external_reference ?? null,
                    'metadata' => $initialPayment->metadata ?? [],
                    'description' => $initialPayment->description ?? null,
                ];
                
                // Encontrar wallet correta
                $wallet = $this->findWalletForPayment($paymentDetails);
            }
        } catch (\Exception $e) {
            Log::error('MercadoPago Webhook: Failed to get initial payment details', [
                'payment_id' => $paymentId,
                'error' => $e->getMessage()
            ]);
        }

        // Se não encontrou wallet, tentar buscar pelo pedido diretamente
        if (!$wallet && $paymentDetails) {
            $orderCode = $this->extractOrderCodeFromPayment($paymentDetails);
            
            if ($orderCode) {
                $order = Order::where('code', $orderCode)->first();
                
                if ($order && $order->company_id) {
                    $wallet = Wallet::where('company_id', $order->company_id)
                        ->where('is_active', true)
                        ->first();
                }
            }
        }

        if (!$wallet) {
            Log::error('MercadoPago Webhook: No active wallet found for payment', [
                'payment_id' => $paymentId,
                'payment_details' => $paymentDetails
            ]);
            
            return [
                'processed' => false,
                'reason' => 'No active wallet found',
                'payment_id' => $paymentId
            ];
        }

        // Buscar detalhes completos com o token da wallet
        $paymentDetails = $this->getPaymentDetails($paymentId, $wallet->mp_access_token);
        
        if (!$paymentDetails) {
            Log::error('MercadoPago Webhook: Failed to get payment details', [
                'payment_id' => $paymentId,
                'wallet_id' => $wallet->id
            ]);
            
            return [
                'processed' => false,
                'reason' => 'Failed to get payment details',
                'payment_id' => $paymentId
            ];
        }

        return [
            'processed' => true,
            'payment' => $paymentDetails,
            'wallet' => $wallet,
            'order_code' => $this->extractOrderCodeFromPayment($paymentDetails)
        ];
    }

    /**
     * Verifica se o pagamento está aprovado
     *
     * @param array $payment
     * @return bool
     */
    public function isPaymentApproved(array $payment): bool
    {
        return $payment['status'] === 'approved';
    }

    /**
     * Verifica se o pagamento está pendente
     *
     * @param array $payment
     * @return bool
     */
    public function isPaymentPending(array $payment): bool
    {
        return in_array($payment['status'], ['pending', 'in_process', 'authorized']);
    }

    /**
     * Verifica se o pagamento foi rejeitado
     *
     * @param array $payment
     * @return bool
     */
    public function isPaymentRejected(array $payment): bool
    {
        return in_array($payment['status'], ['rejected', 'cancelled', 'refunded', 'charged_back']);
    }

    /**
     * Formata o método de pagamento para exibição
     *
     * @param array $payment
     * @return string
     */
    public function formatPaymentMethod(array $payment): string
    {
        $method = $payment['payment_method_id'] ?? 'unknown';
        
        $methods = [
            'pix' => 'PIX',
            'credit_card' => 'Cartão de Crédito',
            'debit_card' => 'Cartão de Débito',
            'ticket' => 'Boleto',
            'account_money' => 'Saldo Mercado Pago',
        ];
        
        return $methods[$method] ?? ucfirst(str_replace('_', ' ', $method));
    }

    /**
     * Formata o valor para exibição
     *
     * @param float $amount
     * @param string $currency
     * @return string
     */
    public function formatAmount(float $amount, string $currency = 'BRL'): string
    {
        if ($currency === 'BRL') {
            return 'R$ ' . number_format($amount, 2, ',', '.');
        }
        
        return number_format($amount, 2) . ' ' . $currency;
    }
}