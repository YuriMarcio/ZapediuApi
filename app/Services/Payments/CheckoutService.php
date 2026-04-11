<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class CheckoutService
{
    public function __construct(
        private readonly MercadoPagoClient $mercadoPago,
        private readonly SplitCalculatorService $splitCalculator,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function createForOrder(Order $order, array $context = [], ?Request $request = null): array
    {
        $order->loadMissing(['company.plan', 'store', 'user.primaryPhone']);

        $deliveryMode = ($context['delivery_mode'] ?? data_get($order->raw_payload, 'checkout.delivery_mode', 'store')) === 'platform'
            ? 'platform'
            : 'store';

        $split = $this->splitCalculator->calculateForOrder($order, $deliveryMode);
        $preferencePayload = $this->buildPreferencePayload($order, $split, $context);
        $response = $this->mercadoPago->createCheckoutPreference($preferencePayload);

        $checkoutUrl = (string) ($response['init_point'] ?? $response['sandbox_init_point'] ?? '');

        $transaction = PaymentTransaction::query()->create([
            'company_id' => $order->company_id,
            'order_id' => $order->id,
            'provider' => 'mercado_pago',
            'external_reference' => $order->code,
            'payment_status' => 'checkout_created',
            'gross_amount' => $split['total_amount'],
            'platform_fee_amount' => $split['platform_amount'],
            'seller_amount' => $split['seller_amount'],
            'products_amount' => $split['products_amount'],
            'delivery_fee_amount' => $split['delivery_fee_amount'],
            'delivery_mode' => $deliveryMode,
            'plan_slug' => $split['plan_slug'],
            'checkout_url' => $checkoutUrl,
            'raw_payload' => [
                'request' => $preferencePayload,
                'response' => $response,
            ],
        ]);

        $this->auditLogger->log('payment.checkout.created', [
            'company_id' => $order->company_id,
            'entity_type' => Order::class,
            'entity_id' => $order->id,
            'metadata' => [
                'transaction_id' => $transaction->id,
                'provider' => 'mercado_pago',
                'external_reference' => $order->code,
                'delivery_mode' => $deliveryMode,
                'platform_fee_amount' => $split['platform_amount'],
                'seller_amount' => $split['seller_amount'],
            ],
        ], $request);

        return [
            'provider' => 'mercado_pago',
            'checkout_url' => $checkoutUrl,
            'preference_id' => $response['id'] ?? null,
            'external_reference' => $order->code,
            'split' => $split,
            'payment_methods' => [
                'credit_card' => ['installments' => 1],
                'pix' => ['enabled' => true],
            ],
            'raw' => $response,
        ];
    }

    /**
     * @param  array<string, mixed>  $split
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function buildPreferencePayload(Order $order, array $split, array $context): array
    {
        $notificationUrl = trim((string) config('services.mercado_pago.webhook_url', route('api.webhooks.payment')));
        $successUrl = trim((string) ($context['success_url'] ?? config('services.mercado_pago.success_url', '')));
        $failureUrl = trim((string) ($context['failure_url'] ?? config('services.mercado_pago.failure_url', '')));
        $pendingUrl = trim((string) ($context['pending_url'] ?? config('services.mercado_pago.pending_url', '')));

        $payload = [
            'external_reference' => $order->code,
            'notification_url' => $notificationUrl,
            'statement_descriptor' => (string) config('services.mercado_pago.statement_descriptor', 'DELIVERYZAP'),
            'items' => $this->buildItemsPayload($order),
            'marketplace' => (string) config('services.mercado_pago.marketplace_name', 'DeliveryZap'),
            'marketplace_fee' => $split['platform_amount'],
            'binary_mode' => false,
            'auto_return' => 'approved',
            'payer' => array_filter([
                'name' => $order->user?->name,
                'email' => $order->user?->email,
                'phone' => [
                    'number' => $order->user?->primaryPhone?->phone ?? $order->user?->phone,
                ],
            ], fn ($value) => $value !== null && $value !== ''),
            'payment_methods' => [
                'installments' => 1,
                'default_installments' => 1,
                'excluded_payment_types' => [
                    ['id' => 'ticket'],
                    ['id' => 'atm'],
                    ['id' => 'debit_card'],
                    ['id' => 'prepaid_card'],
                ],
            ],
            'metadata' => [
                'order_id' => $order->id,
                'company_id' => $order->company_id,
                'store_id' => $order->store_id,
                'delivery_mode' => $split['delivery_mode'],
                'plan_slug' => $split['plan_slug'],
                'products_amount' => $split['products_amount'],
                'delivery_fee_amount' => $split['delivery_fee_amount'],
                'platform_fee_amount' => $split['platform_amount'],
                'seller_amount' => $split['seller_amount'],
            ],
        ];

        // Sempre envie o campo success em back_urls, mesmo que os outros estejam vazios
        if ($successUrl !== '') {
            $payload['back_urls'] = [
                'success' => $successUrl,
                'failure' => $failureUrl !== '' ? $failureUrl : null,
                'pending' => $pendingUrl !== '' ? $pendingUrl : null,
            ];
            // Remove os nulos
            $payload['back_urls'] = array_filter($payload['back_urls']);
        }

        return $payload;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildItemsPayload(Order $order): array
    {
        $cartItems = data_get($order->raw_payload, 'cart.items', data_get($order->raw_payload, 'order.items', []));

        if (! is_array($cartItems) || $cartItems === []) {
            return [[
                'id' => (string) $order->id,
                'title' => 'Pedido '.$order->code,
                'quantity' => 1,
                'currency_id' => 'BRL',
                'unit_price' => round((float) $order->subtotal, 2),
            ], [
                'id' => (string) $order->id.'-delivery',
                'title' => 'Entrega',
                'quantity' => 1,
                'currency_id' => 'BRL',
                'unit_price' => round((float) $order->delivery_fee, 2),
            ]];
        }

        $items = collect($cartItems)->map(function (array $item, int $index): array {
            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $basePrice = (float) ($item['base_price'] ?? data_get($item, 'unit_price', data_get($item, 'price', 0)));
            $additionalPrice = (float) ($item['additional_price'] ?? 0);

            return [
                'id' => (string) ($item['product_id'] ?? $index + 1),
                'title' => (string) ($item['product_name'] ?? data_get($item, 'name', 'Produto')),
                'quantity' => $quantity,
                'currency_id' => 'BRL',
                'unit_price' => round($basePrice + $additionalPrice, 2),
            ];
        })->values()->all();

        if ((float) $order->delivery_fee > 0) {
            $items[] = [
                'id' => (string) $order->id.'-delivery',
                'title' => 'Entrega',
                'quantity' => 1,
                'currency_id' => 'BRL',
                'unit_price' => round((float) $order->delivery_fee, 2),
            ];
        }

        return $items;
    }
}