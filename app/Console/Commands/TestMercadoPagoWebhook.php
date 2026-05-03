<?php

namespace App\Console\Commands;

use App\Jobs\Payment\ProcessMercadoPagoWebhookJob;
use App\Models\Order;
use App\Models\Wallet;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TestMercadoPagoWebhook extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'webhook:test-mercadopago 
                            {--order-code= : Código do pedido para testar}
                            {--payment-id= : ID do pagamento simulado}
                            {--status=approved : Status do pagamento (approved, pending, rejected)}
                            {--sync : Processar sincronamente sem fila}
                            {--list : Listar pedidos disponíveis para teste}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Testar o webhook do Mercado Pago com dados simulados';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if ($this->option('list')) {
            return $this->listOrders();
        }

        $orderCode = $this->option('order-code');
        $paymentId = $this->option('payment-id') ?? 'test_payment_' . time();
        $status = $this->option('status');

        if (!$orderCode) {
            $orderCode = $this->ask('Digite o código do pedido para testar:');
        }

        $order = Order::where('code', $orderCode)->first();

        if (!$order) {
            $this->error("Pedido com código '{$orderCode}' não encontrado.");
            return 1;
        }

        $this->info("📦 Pedido encontrado:");
        $this->line("   Código: {$order->code}");
        $this->line("   Status: " . ($order->status->value ?? (string)$order->status));
        $this->line("   Pagamento: {$order->payment_status}");
        $this->line("   Total: R$ " . number_format($order->total, 2, ',', '.'));

        if ($order->company_id) {
            $this->line("   Company ID: {$order->company_id}");

            $wallet = Wallet::where('company_id', $order->company_id)->first();
            if ($wallet) {
                $this->line("   Wallet: " . ($wallet->is_active ? '✅ Ativa' : '❌ Inativa'));
            }
        }

        if (!$this->confirm("Deseja simular um webhook para este pedido?")) {
            return 0;
        }

        // Criar payload simulado do webhook
        $webhookData = $this->createMockWebhookData($order, $paymentId, $status);

        $this->info("🔄 Criando webhook simulado:");
        $this->line("   Payment ID: {$paymentId}");
        $this->line("   Status: {$status}");
        $this->line("   External Reference: order_{$order->code}");

        if ($this->option('sync')) {
            $this->info("⚡ Processando sincronamente...");

            try {
                // Processar diretamente (para testes)
                $job = new ProcessMercadoPagoWebhookJob($webhookData, $paymentId);
                $job->handle(
                    app(\App\Services\Payment\MercadoPagoPaymentService::class),
                    app(\App\Services\Whatsapp\WhatsAppOrchestrator::class)
                );

                $this->info("✅ Webhook processado com sucesso!");

                // Recarregar pedido para ver mudanças
                $order->refresh();
                $this->info("📊 Status atualizado:");
                $this->line("   Payment Status: {$order->payment_status}");
                $this->line("   MP Payment ID: {$order->mp_payment_id}");
                $this->line("   MP Payment Status: {$order->mp_payment_status}");

            } catch (\Exception $e) {
                $this->error("❌ Erro ao processar webhook: " . $e->getMessage());
                Log::error('TestMercadoPagoWebhook: Failed to process', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                return 1;
            }
        } else {
            $this->info("📤 Enfileirando job para processamento assíncrono...");

            try {
                ProcessMercadoPagoWebhookJob::dispatch($webhookData, $paymentId)
                    ->onQueue('payments');

                $this->info("✅ Job enfileirado com sucesso!");
                $this->line("   Fila: payments");
                $this->line("   Execute 'php artisan queue:work --queue=payments' para processar");

            } catch (\Exception $e) {
                $this->error("❌ Erro ao enfileirar job: " . $e->getMessage());
                return 1;
            }
        }

        $this->newLine();
        $this->info("📝 Logs disponíveis em: storage/logs/laravel.log");

        return 0;
    }

    /**
     * Listar pedidos disponíveis para teste
     */
    private function listOrders(): void
    {
        $orders = Order::orderBy('created_at', 'desc')
            ->limit(20)
            ->get(['code', 'status', 'payment_status', 'total', 'company_id', 'created_at']);

        $this->info("📋 Últimos 20 pedidos:");

        $headers = ['Código', 'Status', 'Pagamento', 'Total', 'Company ID', 'Criado em'];
        $rows = [];

        foreach ($orders as $order) {
            $status = $order->status;
            if ($status instanceof \BackedEnum) {
                $status = $status->value;
            } elseif ($status instanceof \UnitEnum) {
                $status = $status->name;
            }
            $rows[] = [
                $order->code,
                $status,
                $order->payment_status,
                'R$ ' . number_format($order->total, 2, ',', '.'),
                $order->company_id ?? 'N/A',
                $order->created_at->format('d/m/Y H:i'),
            ];
        }
        $this->table($headers, $rows);
    }

    /**
     * Criar dados simulados do webhook do Mercado Pago
     *
     * @param Order $order
     * @param string $paymentId
     * @param string $status
     * @return array
     */
    private function createMockWebhookData(Order $order, string $paymentId, string $status): array
    {
        $statusMap = [
            'approved' => 'approved',
            'pending' => 'in_process',
            'rejected' => 'rejected',
        ];

        $mpStatus = $statusMap[$status] ?? 'approved';

        return [
            'id' => 1234567890,
            'live_mode' => false,
            'type' => 'payment',
            'date_created' => now()->toIso8601String(),
            'user_id' => 123456789,
            'api_version' => 'v1',
            'action' => 'payment.updated',
            'data' => [
                'id' => $paymentId,
            ],
            // Dados adicionais para simulação
            '_simulated' => true,
            '_order_code' => $order->code,
            '_status' => $mpStatus,
            '_external_reference' => "order_{$order->code}",
            '_transaction_amount' => $order->total,
            '_payment_method_id' => 'pix',
        ];
    }
}
