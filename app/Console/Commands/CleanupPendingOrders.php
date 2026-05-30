<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\Zapi\Flows\FlowManager;
use App\Services\Zapi\ZapiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupPendingOrders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orders:cleanup-pending';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up pending orders older than 50 minutes and reset customer sessions';

    /**
     * Execute the console command.
     */
    public function handle(FlowManager $flow, ZapiClient $zapiClient): void
    {
        $this->info('Starting cleanup of pending orders older than 50 minutes...');

        $expiredOrders = Order::where('status', 'pending')
            ->where('payment_status', '!=', 'paid')
            ->where('created_at', '<', now()->subMinutes(50))
            ->with(['user', 'user.primaryPhone'])
            ->get();

        $this->info("Found {$expiredOrders->count()} expired orders.");

        foreach ($expiredOrders as $order) {
            $this->processExpiredOrder($order, $flow, $zapiClient);
        }

        $this->info('Cleanup completed.');
    }

    /**
     * Process a single expired order
     */
    private function processExpiredOrder(Order $order, FlowManager $flow, ZapiClient $zapiClient): void
    {
        try {
            // Get customer phone
            $customerPhone = $order->user?->phone;
            if (!$customerPhone && $order->user?->primaryPhone) {
                $customerPhone = $order->user->primaryPhone->phone;
            }

            if ($customerPhone) {
                // Reset customer session
                $flow->resetState($customerPhone);
                
                // Notify user about expired payment link
                $this->notifyCustomer($customerPhone, $order, $zapiClient);
                
                $this->info("Reset session for order #{$order->code} (customer: {$customerPhone})");
            }

            // Mark order as cancelled due to timeout
            $order->update([
                'status' => 'cancelled',
                'rejection_reason' => 'payment_timeout',
            ]);

            Log::info('Expired order cleaned up', [
                'order_id' => $order->id,
                'order_code' => $order->code,
                'customer_phone' => $customerPhone,
            ]);

        } catch (\Throwable $e) {
            Log::error('Failed to process expired order', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
            
            $this->error("Error processing order #{$order->code}: {$e->getMessage()}");
        }
    }

    /**
     * Notify customer about expired payment link
     */
    private function notifyCustomer(string $phone, Order $order, ZapiClient $zapiClient): void
    {
        try {
            $message = "⏰ *Link de pagamento expirado*\n\n";
            $message .= "Seu pedido #{$order->code} expirou após 50 minutos sem pagamento.\n\n";
            $message .= "💰 Valor: R$ " . number_format($order->total, 2, ',', '.') . "\n";
            $message .= "⏰ Criado: " . $order->created_at->format('d/m H:i') . "\n\n";
            $message .= "Deseja fazer um novo pedido? Digite *oi* para começar!";
            
            $zapiClient->sendText($phone, $message);
            
            $this->info("Notification sent to {$phone} for order #{$order->code}");
        } catch (\Throwable $e) {
            Log::warning('Failed to send expiration notification', [
                'phone' => $phone,
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
            
            $this->warn("Failed to send notification to {$phone}: {$e->getMessage()}");
        }
    }
}