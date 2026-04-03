<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Order;
use App\Models\Scopes\CompanyScope;
use App\Services\Zapi\ZapiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
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
            'status'         => 'confirmed',
        ]);

        $customerPhone = $phone !== '' ? $phone : (string) ($order->customer_phone ?? '');

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
}
