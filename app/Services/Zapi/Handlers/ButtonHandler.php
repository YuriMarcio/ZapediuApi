<?php

namespace App\Services\Zapi\Handlers;

use App\Services\Zapi\ZapiClient;
use App\Services\Zapi\Flows\FlowManager;
use App\Services\Zapi\Flows\GreetingFlow;
use App\Services\Zapi\Flows\CartFlow;
use App\Services\Zapi\Flows\CheckoutFlow;
use App\Services\Zapi\Handlers\StoreHandle;
use App\Services\Zapi\Handlers\ProductsHandler;
use App\Services\Zapi\Handlers\CategoriesHandle;
use App\Models\Product;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class ButtonHandler
{
    public function __construct(
        private FlowManager $flow,
        private GreetingFlow $greetingFlow,
        private ZapiClient $zapiClient,
        private CartFlow $cartFlow,
        private CheckoutFlow $checkoutFlow,
        private StoreHandle $storeHandle,
        private ProductsHandler $productsHandler,
        private CategoriesHandle $categoriesHandle
    ) {
    }

    public function handle(string $phone, string $buttonId): bool
    {
        $buttonId = strtolower(trim($buttonId));

        // 🛡️ TRAVA ANTI-SPAM: Cria uma chave única por Telefone + Botão
        $lockKey = "zapi:lock:button:{$phone}:{$buttonId}";

        // Se a chave existir no cache, significa que já estamos processando esse clique
        if (Cache::has($lockKey)) {
            Log::info('Clique duplicado ignorado', ['phone' => $phone, 'buttonId' => $buttonId]);
            return true; // Retornamos true para o Webhook não tentar reenviar
        }

        // Grava a trava por 2 segundos (tempo suficiente para o processamento terminar)
        Cache::put($lockKey, true, 2);

        Log::info('ButtonHandler::handle', ['phone' => $phone, 'buttonId' => $buttonId]);

        try {
            $result = $this->handleFlowButton($phone, $buttonId);

            if ($result) {
                return true;
            }
            Log::info("Button ID {$buttonId} não mapeado como fluxo, tentando intent genérica...");
            return $this->handleCommerceReplyIntent($phone, $buttonId);
        } catch (\Throwable $e) {
            // Se der erro, removemos a trava para o usuário poder tentar de novo
            Cache::forget($lockKey);

            Log::error('ButtonHandler::handle failed', [
                'buttonId' => $buttonId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function handleAcceptOrder(string $phone, int $orderId)
    {
        $lockKey = "lock:order:{$orderId}";

        // 1. Tenta obter o lock no Redis (expira em 30s por segurança)
        if (!Redis::set($lockKey, 'locked', 'NX', 'EX', 30)) {
            return $this->zapiClient->sendText($phone, "❌ Este pedido já foi aceito por outro colega.");
        }

        try {
            // 2. Update Atômico no Banco
            $affected = DB::update("
            UPDATE orders 
            SET status = 'delivering', driver_id = ?, accepted_at = NOW() 
            WHERE id = ? AND status = 'preparToDelivery'
        ", [$driverId, $orderId]);

            if ($affected === 0) {
                return $this->zapiClient->sendText($phone, "❌ Tarde demais! Outro entregador foi mais rápido.");
            }

            // 3. Sucesso! Notifica o vencedor no privado
            $this->sendOrderDetailsToDriver($phone, $orderId);

            // 4. Edita a mensagem no Grupo para remover os botões
            $this->editGroupMessageAsAccepted($orderId, $driverName);

        } finally {
            Redis::del($lockKey); // Libera o lock
        }
    }

    private function handleFlowButton(string $phone, string $buttonId): bool
    {
        // FLUXO DE FINALIZAR CORRIDA (motoboy)
        if (str_starts_with($buttonId, 'finish_order|')) {
            $orderId = explode('|', $buttonId)[1] ?? null;
            if ($orderId) {
                // Salva no Redis que este motoboy está na tela de digitar código (Expira em 2 horas)
                \Illuminate\Support\Facades\Redis::set("waiting_code:{$phone}", $orderId, 'EX', 7200);
                $this->zapiClient->sendText($phone, "🔑 *Informe o código do cliente!*\n\nPeça ao cliente o codigo de 5 caracteres e *digite aqui* para finalizar a entrega:");
                return true;
            }
        }
        // 1. IDs EXATOS (Switch para performance)
        switch ($buttonId) {
            case 'btn_ver_lojas':
            case 'flow_back_stores':
                return $this->categoriesHandle->returnToStores($phone);

            case 'btn_ver_categorias':
                return $this->categoriesHandle->sendCategoriesCarousel($phone);

            case 'flow_home':
                return $this->greetingFlow->sendWelcomePrompt($phone);

            case 'flow_finalize_order':
            case 'flow_checkout':
            case 'checkout_pay_now_from_cart':
                return $this->checkoutFlow->finalizeCart($phone);

            case 'flow_cart':
                return $this->cartFlow->sendCartSummary($phone);

            case 'checkout_confirm_data':
            case 'checkout_confirm_address':
                return $this->checkoutFlow->sendOrderSummary($phone);

            case 'checkout_pay_now':
                return $this->checkoutFlow->processPayment($phone);

            case 'checkout_skip_email':
                return $this->checkoutFlow->skipEmailAndConfirm($phone);

            case 'flow_edit_cart':
                return $this->cartFlow->sendEditCartCarousel($phone);

            case 'cart_add_more':
                return $this->cartFlow->handleAddMoreItems($phone);
        }

        // 2. IDs DINÂMICOS (Prefixos e Regex)

        // NOVO PEDIDO: order_new_{id} - Fazer novo pedido (limpa sessão atual)
        if (str_starts_with($buttonId, 'order_new_')) {
            $orderId = (int) str_replace('order_new_', '', $buttonId);
            
            // Clear current state (including active_order)
            $this->flow->resetState($phone);
            
            // Optional: mark previous order as abandoned
            $order = \App\Models\Order::find($orderId);
            if ($order && $order->status === 'pending' && $order->payment_status !== 'paid') {
                $order->update(['status' => 'cancelled', 'rejection_reason' => 'abandoned']);
            }
            
            // Start fresh flow
            return $this->greetingFlow->sendWelcomePrompt($phone);
        }

        // RETOMAR PAGAMENTO: order_resume_{id} - Reenviar link de pagamento
        if (str_starts_with($buttonId, 'order_resume_')) {
            $orderId = (int) str_replace('order_resume_', '', $buttonId);
            $order = \App\Models\Order::find($orderId);
            
            if (!$order) {
                $this->zapiClient->sendText($phone, "❌ Pedido não encontrado.");
                return true;
            }
            
            // Get payment link from state or regenerate
            $state = $this->flow->getState($phone);
            $paymentLink = $state['active_order']['payment_link'] ?? $state['last_payment_link'] ?? '';
            
            if (empty($paymentLink)) {
                // Regenerate payment link
                $paymentLink = $this->checkoutFlow->buildPaymentLink(
                    $phone,
                    $order->store->slug ?? '',
                    [],
                    (float) $order->total,
                    $order->code
                );
            }
            
            $message = "🔗 *Link de pagamento reenviado!*\n\n";
            $message .= "🧾 Pedido: #{$order->code}\n";
            $message .= "💰 Valor: R$ " . number_format($order->total, 2, ',', '.') . "\n\n";
            $message .= "Clique no botão abaixo para pagar:";
            
            $this->zapiClient->sendButtonActions(
                $phone,
                $message,
                [['type' => 'URL', 'url' => $paymentLink, 'label' => '🔗 Abrir link de pagamento']]
            );
            
            return true;
        }

        // CANCELAR PEDIDO: order_cancel_{id} - Cancelar pedido pendente
        if (str_starts_with($buttonId, 'order_cancel_')) {
            $orderId = (int) str_replace('order_cancel_', '', $buttonId);
            $order = \App\Models\Order::find($orderId);
            
            if (!$order) {
                $this->zapiClient->sendText($phone, "❌ Pedido não encontrado.");
                return true;
            }
            
            if ($order->status !== 'pending' || $order->payment_status === 'paid') {
                $this->zapiClient->sendText($phone, "❌ Este pedido não pode ser cancelado (já pago ou em andamento).");
                return true;
            }
            
            // Cancel order
            $order->update(['status' => 'cancelled', 'rejection_reason' => 'customer_cancelled']);
            
            // Clear active order from state
            $state = $this->flow->getState($phone);
            unset($state['active_order']);
            $this->flow->saveState($phone, $state);
            
            $this->zapiClient->sendText($phone, "✅ Pedido #{$order->code} cancelado com sucesso!\n\nDigite *oi* para fazer um novo pedido.");
            return true;
        }

        // NOVA REGRA: Esvaziar carrinho e adicionar item de outra loja
        if (str_starts_with($buttonId, 'cart_clear_and_add_')) {
            $productId = (int) str_replace('cart_clear_and_add_', '', $buttonId);

            // Limpa o carrinho no estado
            $state = $this->flow->getState($phone);
            $state['cart'] = ['store_id' => null, 'items' => []];
            $this->flow->saveState($phone, $state);

            // Busca o produto para saber de qual loja ele é e reiniciar o fluxo
            $product = Product::with('store')->find($productId);
            if ($product && $product->store) {
                return $this->cartFlow->startAddProductFlow($phone, $product->store->slug, (string)$productId);
            }
            return false;
        }

        if (str_starts_with($buttonId, 'cart_remove_')) {
            $index = (int) str_replace('cart_remove_', '', $buttonId);
            return $this->cartFlow->removeItem($phone, $index);
        }

        // Seleção de Categoria
        if (str_starts_with($buttonId, 'buscar_cat_')) {
            return $this->categoriesHandle->handleCategorySearch($phone, $buttonId);
        }

        // Seleção de Variação de Produto
        if (str_starts_with($buttonId, 'flow_variation_')) {
            return $this->cartFlow->handleVariationSelected($phone, str_replace('flow_variation_', '', $buttonId));
        }

        // Paginação de Lojas
        if (preg_match('/^(flow|view)_more_(\d+)$/', $buttonId, $matches)) {
            return $this->storeHandle->sendStoresPage($phone, (int) $matches[2]);
        }

        // Seleção de Loja (Ver Cardápio / Categorias)
        if (preg_match('/^(flow_store|view_menu)_([a-z0-9_\-]+)$/', $buttonId, $matches)) {
            return $this->storeHandle->selectStore($phone, $matches[2]);
        }

        // Ver Produtos de uma Categoria específica da Loja
        if (str_starts_with($buttonId, 'view_category_')) {
            $categorySlug = substr($buttonId, strlen('view_category_'));
            $state = $this->flow->getState($phone);
            $storeSlug = $state['selected_store_id'] ?? null;
            if ($storeSlug) {
                return $this->storeHandle->sendProductsByCategoryCarousel($phone, $storeSlug, $categorySlug, 0);
            }
        }

        // Adicionar Produto ao Carrinho (Regex para capturar quantidade, loja e ID)
        $addPayload = $this->resolveAddButtonPayload($buttonId);
        if ($addPayload) {
            return $this->cartFlow->startAddProductFlow(
                $phone,
                $addPayload['store_id'],
                $addPayload['product_id'],
                $addPayload['quantity']
            );
        }

        return false;
    }

    private function resolveAddButtonPayload(string $buttonId): ?array
    {
        // Padrão: flow_add{qty}_{storeSlug}_{productId}
        if (preg_match('/^flow_add([123])_([a-z0-9_\-]+)_(\d+)$/', $buttonId, $matches)) {
            return [
                'quantity'   => (int) $matches[1],
                'store_id'   => $matches[2],
                'product_id' => (int) $matches[3],
            ];
        }

        // Padrão simples: flow_add_{storeSlug}_{productId}
        if (preg_match('/^flow_add_([a-z0-9_\-]+)_(\d+)$/', $buttonId, $matches)) {
            return [
                'quantity'   => 1,
                'store_id'   => $matches[1],
                'product_id' => (int) $matches[2],
            ];
        }

        return null;
    }

    private function handleCommerceReplyIntent(string $phone, string $buttonId): bool
    {
        Log::info("Botão não mapeado recebido: {$buttonId} de {$phone}");
        return false;
    }
}
