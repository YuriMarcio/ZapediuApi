<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\Payment\PaymentLinkBuilder;
use App\Services\Whatsapp\WhatsAppClientInterface;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendPendingPaymentReminders extends Command
{
    protected $signature = 'orders:send-pending-payment-reminders';

    protected $description = 'Envia um lembrete único (§13.2) pra pedidos pendentes de pagamento há 20 minutos';

    public function handle(WhatsAppClientInterface $zapiClient, PaymentLinkBuilder $paymentLinkBuilder): void
    {
        $orders = Order::query()
            ->where('status', 'pending')
            ->where('payment_status', '!=', 'paid')
            ->whereNull('reminder_sent_at')
            ->where('created_at', '<=', now()->subMinutes(20))
            ->where('created_at', '>', now()->subMinutes(50))
            ->with(['user', 'user.primaryPhone', 'store'])
            ->get();

        $this->info("Found {$orders->count()} pending orders eligible for a payment reminder.");

        foreach ($orders as $order) {
            $this->sendReminder($order, $zapiClient, $paymentLinkBuilder);
        }
    }

    private function sendReminder(Order $order, WhatsAppClientInterface $zapiClient, PaymentLinkBuilder $paymentLinkBuilder): void
    {
        $phone = $order->user?->phone ?: $order->user?->primaryPhone?->phone;

        if (! $phone) {
            Log::warning('Skipping payment reminder — no phone on order.', ['order_id' => $order->id]);

            return;
        }

        // Comando roda sem TenantContext (fora do webhook): seta a company do pedido pra o
        // FlowBridgeClient resolver a instância WhatsApp certa (company -> operation ->
        // whatsappSession) em vez de cair na instância default do .env.
        app(TenantContext::class)->setCompanyId($order->company_id ? (int) $order->company_id : null);

        try {
            $items = (array) ($order->raw_payload['cart']['items'] ?? []);
            $itemLines = collect($items)
                ->map(fn (array $item) => '🍔 '.($item['product_name'] ?? 'Produto').' ('.((int) ($item['quantity'] ?? 1)).'x)')
                ->implode("\n");

            $message = "⏰ *Ainda dá tempo!* Seu pedido tá te esperando.\n\n"
                .$itemLines
                ."\n💰 *Total: R$ ".number_format((float) $order->total, 2, ',', '.')."*"
                ."\n\nO Chef já separou os ingredientes — só falta você! 👨‍🍳";

            $paymentLink = $paymentLinkBuilder->forOrder($order);

            $storeName = trim((string) ($order->store->name ?? ''));
            $zapiClient->sendButtonActions($phone, $message, [
                ['type' => 'URL', 'url' => $paymentLink, 'label' => '🔗 Pagar Agora'],
                ['id' => 'order_view_'.$order->id, 'label' => '🛒 Ver Carrinho'],
            ], $storeName !== '' ? 'Zapediu & '.$storeName : 'Zapediu');

            $order->update(['reminder_sent_at' => now()]);

            $this->info("Reminder sent for order #{$order->code} ({$phone}).");
        } catch (\Throwable $e) {
            Log::error('Failed to send pending payment reminder.', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
