<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\Scopes\CompanyScope;
use App\Services\Payments\CheckoutService;
use App\Services\Payments\MercadoPagoClient;
use App\Services\Payments\SplitCalculatorService;
use App\Services\Whatsapp\WhatsAppOrchestrator;
use App\Support\Audit\AuditLogger;
use App\Services\Zapi\ZapiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class PaymentWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        MercadoPagoClient $mercadoPago,
        SplitCalculatorService $splitCalculator,
        CheckoutService $checkoutService,
        AuditLogger $auditLogger,
        WhatsAppOrchestrator $whatsApp,
    ): JsonResponse
    {
        $paymentId = trim((string) ($request->input('data.id') ?? $request->input('id') ?? ''));

        if ($paymentId !== '') {
            return $this->handleMercadoPagoWebhook(
                $paymentId,
                $request,
                $mercadoPago,
                $splitCalculator,
                $checkoutService,
                $auditLogger,
                $whatsApp,
            );
        }

        return $this->handleLegacyWebhook($request);
    }

    private function handleLegacyWebhook(Request $request): JsonResponse
    {
        $status    = strtolower(trim((string) $request->input('status', '')));
        $reference = trim((string) ($request->input('reference') ?? $request->input('order_code') ?? $request->input('code') ?? ''));
        $phone     = trim((string) $request->input('phone', ''));

        if ($status !== 'paid' || $reference === '') {
            return response()->json(['ok' => false, 'error' => 'missing or unsupported status/reference']);
        }

        /** @var Order|null $order */
        $order = Order::withoutGlobalScope(CompanyScope::class)
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
            /** @var Company|null $company */
            $company = Company::query()->find($order->company_id);

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
            app(ZapiClient::class)->sendText($customerPhone, $message);
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

    private function handleMercadoPagoWebhook(
        string $paymentId,
        Request $request,
        MercadoPagoClient $mercadoPago,
        SplitCalculatorService $splitCalculator,
        CheckoutService $checkoutService,
        AuditLogger $auditLogger,
        WhatsAppOrchestrator $whatsApp,
    ): JsonResponse {
        try {
            $payment = $mercadoPago->getPayment($paymentId);
        } catch (Throwable $exception) {
            Log::warning('MercadoPago webhook: failed to fetch payment.', [
                'payment_id' => $paymentId,
                'error' => $exception->getMessage(),
            ]);

            return response()->json(['ok' => false, 'error' => 'payment lookup failed'], 422);
        }

        $reference = trim((string) data_get($payment, 'external_reference', ''));

        if ($reference === '') {
            return response()->json(['ok' => false, 'error' => 'missing external reference'], 422);
        }

        /** @var Order|null $order */
        $order = Order::withoutGlobalScope(CompanyScope::class)
            ->where('code', $reference)
            ->with(['company.plan', 'store', 'user.primaryPhone'])
            ->first();

        if ($order === null) {
            Log::warning('MercadoPago webhook: order not found.', [
                'payment_id' => $paymentId,
                'reference' => $reference,
            ]);

            return response()->json(['ok' => false, 'error' => 'order not found'], 404);
        }

        $paymentStatus = strtolower(trim((string) data_get($payment, 'status', 'pending')));
        $paymentType = $this->resolvePaymentType($payment);
        $deliveryMode = (string) data_get($payment, 'metadata.delivery_mode', data_get($order->raw_payload, 'checkout.delivery_mode', 'store'));
        $split = $splitCalculator->calculateForOrder($order, $deliveryMode);
        $approvedAt = $paymentStatus === 'approved' ? now() : null;

        $transaction = PaymentTransaction::query()
            ->withoutGlobalScopes()
            ->where('provider', 'mercado_pago')
            ->where(function ($query) use ($paymentId, $reference): void {
                $query->where('external_id', $paymentId)
                    ->orWhere('external_reference', $reference);
            })
            ->latest('id')
            ->first() ?? new PaymentTransaction([
                'company_id' => $order->company_id,
                'order_id' => $order->id,
                'provider' => 'mercado_pago',
            ]);

        $transaction->fill([
            'company_id' => $order->company_id,
            'order_id' => $order->id,
            'external_id' => $paymentId,
            'external_reference' => $reference,
            'payment_status' => $paymentStatus,
            'payment_type' => $paymentType,
            'payment_method' => (string) data_get($payment, 'payment_method_id', $paymentType),
            'gross_amount' => (float) data_get($payment, 'transaction_amount', $split['total_amount']),
            'net_received_amount' => (float) data_get($payment, 'transaction_details.net_received_amount', 0),
            'platform_fee_amount' => (float) data_get($payment, 'application_fee', $split['platform_amount']),
            'seller_amount' => $split['seller_amount'],
            'products_amount' => $split['products_amount'],
            'delivery_fee_amount' => $split['delivery_fee_amount'],
            'delivery_mode' => $deliveryMode,
            'plan_slug' => $split['plan_slug'],
            'payout_mode' => $splitCalculator->resolvePayoutMode($order->company?->plan, $paymentType),
            'seller_release_at' => $approvedAt !== null
                ? $splitCalculator->resolveSellerReleaseAt($order->company?->plan, $paymentType, $approvedAt)
                : $transaction->seller_release_at,
            'approved_at' => $approvedAt ?? $transaction->approved_at,
            'last_webhook_at' => now(),
            'raw_payload' => $payment,
        ]);
        $transaction->save();

        $order->update([
            'payment_status' => $this->mapOrderPaymentStatus($paymentStatus),
            'payment_method' => $paymentType,
            'status' => $paymentStatus === 'approved' ? 'pending' : $order->status,
        ]);

        $auditLogger->log('payment.webhook.processed', [
            'company_id' => $order->company_id,
            'entity_type' => PaymentTransaction::class,
            'entity_id' => $transaction->id,
            'metadata' => [
                'provider' => 'mercado_pago',
                'payment_id' => $paymentId,
                'order_code' => $reference,
                'status' => $paymentStatus,
                'net_received_amount' => $transaction->net_received_amount,
            ],
        ], $request);

        if ($paymentStatus === 'approved') {
            $this->notifyApprovedPayment($order, $whatsApp);

            return response()->json([
                'ok' => true,
                'status' => 'approved',
                'order_code' => $reference,
                'transaction_id' => $paymentId,
                'net_received_amount' => $transaction->net_received_amount,
            ]);
        }

        if ($paymentStatus === 'rejected') {
            $this->notifyRejectedPayment($order, $checkoutService, $whatsApp, $request, $deliveryMode);

            return response()->json([
                'ok' => true,
                'status' => 'rejected',
                'order_code' => $reference,
                'transaction_id' => $paymentId,
            ]);
        }

        return response()->json([
            'ok' => true,
            'status' => $paymentStatus,
            'order_code' => $reference,
            'transaction_id' => $paymentId,
        ]);
    }

    private function notifyApprovedPayment(Order $order, WhatsAppOrchestrator $whatsApp): void
    {
        $customerPhone = (string) ($order->user?->primaryPhone?->phone ?? $order->user?->phone ?? '');

        if ($customerPhone !== '' && $order->company_id !== null) {
            $whatsApp->queueStatusNotification(
                (int) $order->company_id,
                $customerPhone,
                'Pagamento aprovado do pedido {order_code}. Total R$ {total}. Agora vamos iniciar a producao.',
                [
                    'order_code' => $order->code,
                    'status' => 'approved',
                    'total' => number_format((float) $order->total, 2, ',', '.'),
                ]
            );
        }

        $storePhone = trim((string) ($order->store?->whatsapp_phone ?? $order->store?->phone ?? ''));

        if ($storePhone !== '' && $order->company_id !== null) {
            $whatsApp->queueStatusNotification(
                (int) $order->company_id,
                $storePhone,
                'Novo pedido pago {order_code}. Inicie a producao. Total R$ {total}.',
                [
                    'order_code' => $order->code,
                    'status' => 'approved',
                    'total' => number_format((float) $order->total, 2, ',', '.'),
                ]
            );
        }
    }

    private function notifyRejectedPayment(
        Order $order,
        CheckoutService $checkoutService,
        WhatsAppOrchestrator $whatsApp,
        Request $request,
        string $deliveryMode,
    ): void {
        $customerPhone = (string) ($order->user?->primaryPhone?->phone ?? $order->user?->phone ?? '');

        if ($customerPhone === '' || $order->company_id === null) {
            return;
        }

        $checkoutUrl = null;

        try {
            $checkout = $checkoutService->createForOrder($order, ['delivery_mode' => $deliveryMode], $request);
            $checkoutUrl = $checkout['checkout_url'] ?? null;
        } catch (Throwable $exception) {
            Log::warning('MercadoPago webhook: failed to regenerate checkout after rejection.', [
                'order_code' => $order->code,
                'error' => $exception->getMessage(),
            ]);
        }

        $template = 'Pagamento recusado para o pedido {order_code}. ';
        $template .= $checkoutUrl !== null && $checkoutUrl !== ''
            ? 'Use este novo link para tentar novamente: '.$checkoutUrl
            : 'Responda esta mensagem para gerar um novo link de pagamento.';

        $whatsApp->queueStatusNotification(
            (int) $order->company_id,
            $customerPhone,
            $template,
            [
                'order_code' => $order->code,
                'status' => 'rejected',
                'total' => number_format((float) $order->total, 2, ',', '.'),
            ]
        );
    }

    private function resolvePaymentType(array $payment): string
    {
        $paymentType = strtolower(trim((string) data_get($payment, 'payment_type_id', '')));

        return match ($paymentType) {
            'credit_card', 'creditcard' => 'credit_card',
            'pix', 'bank_transfer' => 'pix',
            default => $paymentType !== '' ? $paymentType : 'unknown',
        };
    }

    private function mapOrderPaymentStatus(string $paymentStatus): string
    {
        return match ($paymentStatus) {
            'approved' => 'paid',
            'rejected', 'cancelled', 'cancelled_by_user' => 'rejected',
            default => $paymentStatus,
        };
    }
}
