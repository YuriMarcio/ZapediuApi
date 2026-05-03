<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\Payment\ProcessMercadoPagoWebhookJob;
use App\Services\Payment\MercadoPagoPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentWebhookController extends Controller
{
    /**
     * Handle payment webhook requests
     *
     * @param Request $request
     * @param MercadoPagoPaymentService $paymentService
     * @return JsonResponse
     */
    public function __invoke(Request $request, MercadoPagoPaymentService $paymentService): JsonResponse
    {
        // Verificar se é uma notificação do Mercado Pago
        $isMercadoPago = $request->has('type') && $request->has('data.id');
        
        if ($isMercadoPago) {
            return $this->handleMercadoPagoWebhook($request, $paymentService);
        }
        
        // Fallback para o webhook antigo (compatibilidade)
        return $this->handleLegacyWebhook($request);
    }

    /**
     * Processa webhook do Mercado Pago (IPN)
     *
     * @param Request $request
     * @param MercadoPagoPaymentService $paymentService
     * @return JsonResponse
     */
    private function handleMercadoPagoWebhook(Request $request, MercadoPagoPaymentService $paymentService): JsonResponse
    {
        Log::info('PaymentWebhook: Mercado Pago webhook received', [
            'type' => $request->input('type'),
            'action' => $request->input('action'),
            'payment_id' => $request->input('data.id'),
            'request_id' => $request->header('x-request-id')
        ]);

        // Validar assinatura do webhook
        if (!$paymentService->validateWebhookSignature($request)) {
            Log::warning('PaymentWebhook: Invalid Mercado Pago webhook signature', [
                'signature' => $request->header('x-signature'),
                'ip' => $request->ip()
            ]);
            
            return response()->json(['ok' => false, 'error' => 'Invalid signature'], 401);
        }

        $type = $request->input('type');
        $paymentId = $request->input('data.id');

        // Só processamos notificações de pagamento
        if ($type !== 'payment') {
            Log::info('PaymentWebhook: Ignoring non-payment Mercado Pago notification', ['type' => $type]);
            return response()->json(['ok' => true, 'ignored' => true, 'reason' => 'Non-payment notification']);
        }

        // Despachar job para processamento assíncrono
        try {
            ProcessMercadoPagoWebhookJob::dispatch(
                $request->all(),
                $paymentId
            )->onQueue('payments');

            Log::info('PaymentWebhook: Mercado Pago webhook queued for processing', [
                'payment_id' => $paymentId,
                'queue' => 'payments'
            ]);

            return response()->json([
                'ok' => true,
                'message' => 'Webhook received and queued for processing',
                'payment_id' => $paymentId
            ]);
        } catch (\Exception $e) {
            Log::error('PaymentWebhook: Failed to queue Mercado Pago webhook', [
                'payment_id' => $paymentId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'ok' => false,
                'error' => 'Failed to process webhook',
                'payment_id' => $paymentId
            ], 500);
        }
    }

    /**
     * Processa webhook legado (compatibilidade)
     *
     * @param Request $request
     * @return JsonResponse
     */
    private function handleLegacyWebhook(Request $request): JsonResponse
    {
        $status    = strtolower(trim((string) $request->input('status', '')));
        $reference = trim((string) ($request->input('reference') ?? $request->input('order_code') ?? $request->input('code') ?? ''));
        $phone     = trim((string) $request->input('phone', ''));

        if ($status !== 'paid' || $reference === '') {
            return response()->json(['ok' => false, 'error' => 'missing or unsupported status/reference']);
        }

        /** @var \App\Models\Order|null $order */
        $order = \App\Models\Order::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)
            ->where('code', $reference)
            ->first();

        if ($order === null) {
            Log::warning('PaymentWebhook: order not found.', ['reference' => $reference]);

            return response()->json(['ok' => false, 'error' => 'order not found'], 404);
        }

        $order->update([
            'payment_status' => 'paid',
            'status'         => 'pending',
        ]);

        $customerPhone = $phone !== '' ? $phone : (string) ($order->user?->primaryPhone?->phone ?? $order->user?->phone ?? '');

        if ($customerPhone === '') {
            Log::warning('PaymentWebhook: no customer phone to notify.', ['order_code' => $reference]);

            return response()->json(['ok' => true, 'notified' => false]);
        }

        // Configure Z-API credentials from the order's company
        if ($order->company_id) {
            /** @var \App\Models\Company|null $company */
            $company = \App\Models\Company::query()->find($order->company_id);

            if ($company !== null) {
                if ($company->zapi_instance_id)    { config()->set('services.zapi.instance_id',    $company->zapi_instance_id); }
                if ($company->zapi_instance_token) { config()->set('services.zapi.instance_token', $company->zapi_instance_token); }
                if ($company->zapi_client_token)   { config()->set('services.zapi.client_token',   $company->zapi_client_token); }
            }
        }

        $storeName = $order->store?->name ?? 'a loja';
        $message   = "✅ *Pagamento confirmado!*\n\n"
            ."Seu pedido já está sendo preparado 🍔🔥\n\n"
            ."📋 *Código do seu pedido:*\n"
            ."`{$reference}`\n\n"
            ."🛵 Quando o entregador chegar, confirme seu pedido com esse código.\n\n"
            ."Obrigado por pediu na *{$storeName}*! 🙏";

        try {
            app(\App\Services\Zapi\ZapiClient::class)->sendText($customerPhone, $message);
        } catch (\Throwable $exception) {
            Log::warning('PaymentWebhook: failed to send WhatsApp confirmation.', [
                'order_code' => $reference,
                'phone'      => $customerPhone,
                'error'      => $exception->getMessage(),
            ]);

            return response()->json(['ok' => true, 'notified' => false]);
        }

        return response()->json(['ok' => true, 'notified' => true, 'order_code' => $reference]);
    }
}
