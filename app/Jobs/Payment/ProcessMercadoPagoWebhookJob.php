<?php

namespace App\Jobs\Payment;

use App\Models\Order;
use App\Models\Company;
use App\Models\Wallet;
use App\Services\Payment\MercadoPagoPaymentService;
use App\Services\Whatsapp\WhatsAppOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessMercadoPagoWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Número de tentativas
     *
     * @var int
     */
    public $tries = 3;

    /**
     * Backoff exponencial entre tentativas (1min, 5min, 15min)
     *
     * @var array
     */
    public $backoff = [60, 300, 900];

    /**
     * Timeout do job (2 minutos)
     *
     * @var int
     */
    public $timeout = 120;

    /**
     * Dados do webhook
     *
     * @var array
     */
    private array $webhookData;

    /**
     * ID do pagamento
     *
     * @var string
     */
    private string $paymentId;

    /**
     * Create a new job instance.
     *
     * @param array $webhookData
     * @param string $paymentId
     */
    public function __construct(array $webhookData, string $paymentId)
    {
        $this->webhookData = $webhookData;
        $this->paymentId = $paymentId;
    }

    /**
     * Execute the job.
     *
     * @param MercadoPagoPaymentService $paymentService
     * @param WhatsAppOrchestrator $whatsappOrchestrator
     * @return void
     */
    public function handle(
        MercadoPagoPaymentService $paymentService,
        WhatsAppOrchestrator $whatsappOrchestrator
    ): void {
        Log::info('🚀 [DEBUG 1] JOB DO MP INICIOU!', [
                    'payment_id' => $this->paymentId,
                    'webhook_data' => $this->webhookData
                ]);

        // Processar o webhook
        $result = $paymentService->processWebhook($this->webhookData);

        if (!$result['processed']) {
            Log::error('ProcessMercadoPagoWebhookJob: Failed to process webhook', $result);
            return;
        }

        $payment = $result['payment'];
        $wallet = $result['wallet'];
        $orderCode = $result['order_code'];

        if (!$orderCode) {
            Log::error('ProcessMercadoPagoWebhookJob: No order code found in payment', [
                'payment_id' => $this->paymentId,
                'payment' => $payment
            ]);
            return;
        }

        // Buscar o pedido
        $order = Order::where('code', $orderCode)->first();

        if (!$order) {
            Log::error('ProcessMercadoPagoWebhookJob: Order not found', [
                'order_code' => $orderCode,
                'payment_id' => $this->paymentId
            ]);
            return;
        }

        // Verificar se o pedido já foi processado
        if ($order->mp_payment_id === $this->paymentId && $order->payment_status === 'paid') {
            Log::info('ProcessMercadoPagoWebhookJob: Payment already processed', [
                'order_code' => $orderCode,
                'payment_id' => $this->paymentId
            ]);
            return;
        }

        // Atualizar informações do Mercado Pago no pedido
        $this->updateOrderWithPaymentInfo($order, $payment, $wallet);

        // Verificar status do pagamento
        if ($paymentService->isPaymentApproved($payment)) {
            $this->handleApprovedPayment($order, $payment, $whatsappOrchestrator, $paymentService);
        } elseif ($paymentService->isPaymentRejected($payment)) {
            $this->handleRejectedPayment($order, $payment);
        } elseif ($paymentService->isPaymentPending($payment)) {
            $this->handlePendingPayment($order, $payment);
        }

        Log::info('ProcessMercadoPagoWebhookJob: Completed', [
            'order_code' => $orderCode,
            'payment_id' => $this->paymentId,
            'payment_status' => $payment['status'],
            'order_payment_status' => $order->payment_status
        ]);
    }

    /**
     * Atualiza o pedido com informações do pagamento
     *
     * @param Order $order
     * @param array $payment
     * @param Wallet $wallet
     * @return void
     */
    private function updateOrderWithPaymentInfo(Order $order, array $payment, Wallet $wallet): void
    {
        $updateData = [
            'mp_payment_id' => $payment['id'],
            'mp_payment_type' => $payment['payment_type_id'] ?? $payment['payment_method_id'] ?? 'unknown',
            'mp_payment_status' => $payment['status'],
            'payment_method' => $payment['payment_method_id'] ?? null,
        ];

        // Se o pagamento foi aprovado, adicionar data de aprovação
        if ($payment['status'] === 'approved' && !empty($payment['date_approved'])) {
            $updateData['mp_payment_approved_at'] = $payment['date_approved'];
        }

        // Atualizar company_id se não estiver definido
        if (!$order->company_id && $wallet->company_id) {
            $updateData['company_id'] = $wallet->company_id;
        }

        $order->update($updateData);

        Log::info('ProcessMercadoPagoWebhookJob: Order updated with payment info', [
            'order_code' => $order->code,
            'payment_id' => $payment['id'],
            'update_data' => $updateData
        ]);
    }

    /**
     * Processa um pagamento aprovado
     *
     * @param Order $order
     * @param array $payment
     * @param WhatsAppOrchestrator $whatsappOrchestrator
     * @param MercadoPagoPaymentService $paymentService
     * @return void
     */
    private function handleApprovedPayment(
        Order $order,
        array $payment,
        WhatsAppOrchestrator $whatsappOrchestrator,
        MercadoPagoPaymentService $paymentService
    ): void {
        // Atualizar status do pedido
        $order->update([
            'payment_status' => 'paid',
            'status' => 'pending', // Mover para próximo estado
        ]);

        Log::info('ProcessMercadoPagoWebhookJob: Payment approved', [
            'order_code' => $order->code,
            'payment_id' => $payment['id'],
            'amount' => $payment['transaction_amount']
        ]);

        // Enviar notificação WhatsApp
        $this->sendPaymentApprovedNotification($order, $payment, $whatsappOrchestrator, $paymentService);
    }

    /**
     * Processa um pagamento rejeitado
     *
     * @param Order $order
     * @param array $payment
     * @return void
     */
    private function handleRejectedPayment(Order $order, array $payment): void
    {
        $order->update([
            'payment_status' => 'failed',
        ]);

        Log::warning('ProcessMercadoPagoWebhookJob: Payment rejected', [
            'order_code' => $order->code,
            'payment_id' => $payment['id'],
            'status_detail' => $payment['status_detail']
        ]);

        // TODO: Enviar notificação ao cliente sobre pagamento rejeitado
        // (opcional, dependendo do fluxo desejado)
    }

    /**
     * Processa um pagamento pendente
     *
     * @param Order $order
     * @param array $payment
     * @return void
     */
    private function handlePendingPayment(Order $order, array $payment): void
    {
        $order->update([
            'payment_status' => 'pending',
        ]);

        Log::info('ProcessMercadoPagoWebhookJob: Payment pending', [
            'order_code' => $order->code,
            'payment_id' => $payment['id'],
            'status' => $payment['status'],
            'status_detail' => $payment['status_detail']
        ]);
    }

    /**
     * Envia notificação WhatsApp de pagamento aprovado
     *
     * @param Order $order
     * @param array $payment
     * @param WhatsAppOrchestrator $whatsappOrchestrator
     * @param MercadoPagoPaymentService $paymentService
     * @return void
     */
    private function sendPaymentApprovedNotification(
        Order $order,
        array $payment,
        WhatsAppOrchestrator $whatsappOrchestrator,
        MercadoPagoPaymentService $paymentService
    ): void {
        // Obter telefone do cliente
        $customerPhone = $order->user?->primaryPhone?->phone ?? $order->user?->phone ?? null;

        if (!$customerPhone) {
            Log::warning('ProcessMercadoPagoWebhookJob: No customer phone for WhatsApp notification', [
                'order_code' => $order->code,
                'user_id' => $order->user_id
            ]);
            return;
        }

        // Formatar dados para a mensagem
        $storeName = $order->store?->name ?? 'a loja';
        $paymentMethod = $paymentService->formatPaymentMethod($payment);
        $amount = $paymentService->formatAmount($payment['transaction_amount'], $payment['currency_id'] ?? 'BRL');

        // Formatar data de aprovação
        $approvedAt = !empty($payment['date_approved'])
            ? date('d/m/Y H:i', strtotime($payment['date_approved']))
            : date('d/m/Y H:i');

        // Template da mensagem
        $message = "✅ *Pagamento Confirmado!*\n\n"
            . "Oba! Seu pagamento foi aprovado com sucesso! 🎉\n\n"
            . "📋 *Pedido:* #{$order->code}\n"
            . "🔐 *Código de confirmação:* {$order->code_confirm}\n"
            . "💰 *Valor:* {$amount}\n"
            . "💳 *Forma:* {$paymentMethod}\n"
            . "📅 *Data:* {$approvedAt}\n\n"
            . "🍔 Seu pedido já está sendo preparado com muito carinho!\n"
            . "🛵 Em breve estará a caminho da sua casa!\n\n"
            . "📝 *Importante:* Apresente o código *{$order->code_confirm}* ao entregador para confirmar a entrega.\n\n";

        // Adicionar endereço se disponível
        if ($order->customer_address) {
            $message .= "📍 *Endereço de entrega:*\n"
                . "{$order->customer_address}\n\n";
        }

        // Adicionar previsão se disponível
        if ($order->estimated_ready_at) {
            $estimatedTime = $order->estimated_ready_at->format('H:i');
            $message .= "⏰ *Previsão:* {$estimatedTime}\n\n";
        }

        $message .= "Obrigado por escolher a *{$storeName}*! 🙏";

        try {
            // Configurar credenciais Z-API da company
            if ($order->company_id) {
                $company = Company::find($order->company_id);

                if ($company) {
                    config()->set('services.zapi.instance_id', $company->zapi_instance_id ?: config('services.zapi.instance_id'));
                    config()->set('services.zapi.instance_token', $company->zapi_instance_token ?: config('services.zapi.instance_token'));
                    config()->set('services.zapi.client_token', $company->zapi_client_token ?: config('services.zapi.client_token'));
                }
            }

            // Enviar mensagem
            $whatsappOrchestrator->sendStatusNotificationNow(
                $order->company_id ?? 0,
                $customerPhone,
                $message,
                []
            );

            Log::info('ProcessMercadoPagoWebhookJob: WhatsApp notification sent', [
                'order_code' => $order->code,
                'customer_phone' => $customerPhone
            ]);
        } catch (\Exception $e) {
            Log::error('ProcessMercadoPagoWebhookJob: Failed to send WhatsApp notification', [
                'order_code' => $order->code,
                'customer_phone' => $customerPhone,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle a job failure.
     *
     * @param \Throwable $exception
     * @return void
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessMercadoPagoWebhookJob: Job failed', [
            'payment_id' => $this->paymentId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);

        // TODO: Enviar alerta para administradores
        // (ex: Slack, Email, etc.)
    }
}
