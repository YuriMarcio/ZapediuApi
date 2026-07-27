<?php

namespace App\Services\Zapi\Handlers;

use App\Models\Order;
use App\Models\Product;
use App\Services\Whatsapp\WhatsAppClientInterface;
use App\Services\Zapi\Flows\CartFlow;
use App\Services\Zapi\Flows\CheckoutFlow;
use App\Services\Zapi\Flows\FlowManager;
use App\Services\Zapi\Flows\GreetingFlow;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ButtonHandler
{
    public function __construct(
        private FlowManager $flow,
        private GreetingFlow $greetingFlow,
        private WhatsAppClientInterface $zapiClient,
        private CartFlow $cartFlow,
        private CheckoutFlow $checkoutFlow,
        private StoreHandle $storeHandle,
        private ProductsHandler $productsHandler,
        private CategoriesHandle $categoriesHandle
    ) {}

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
        if (! Redis::set($lockKey, 'locked', 'NX', 'EX', 30)) {
            return $this->zapiClient->sendText($phone, '❌ Este pedido já foi aceito por outro colega.');
        }

        try {
            // 2. Update Atômico no Banco
            $affected = DB::update("
            UPDATE orders 
            SET status = 'delivering', driver_id = ?, accepted_at = NOW() 
            WHERE id = ? AND status = 'preparToDelivery'
        ", [$driverId, $orderId]);

            if ($affected === 0) {
                return $this->zapiClient->sendText($phone, '❌ Tarde demais! Outro entregador foi mais rápido.');
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

            case 'checkout_change_address':
                return $this->checkoutFlow->promptChangeAddress($phone);

            // §12.2 — "📋 Ver Endereços" / "✏️ Mudar Nome"
            case 'checkout_list_addresses':
                return $this->checkoutFlow->sendAddressList($phone);

            case 'checkout_change_name':
                return $this->checkoutFlow->promptChangeName($phone);

            case 'checkout_pay_now':
                return $this->checkoutFlow->processPayment($phone);

            case 'flow_edit_cart':
                return $this->cartFlow->sendEditCartCarousel($phone);

            // §11.1 carrinho vazio "🍔 Ver Cardápio" — volta pro cardápio da mesma loja
            case 'cart_add_more':
            case 'cart_view_menu':
                return $this->cartFlow->handleAddMoreItems($phone);

            // §11.2 caminho B — "📝 Adicionar observação" da confirmação pós-adição
            case 'obs_pick':
                return $this->cartFlow->startObservationPicker($phone);
        }

        // 2. IDs DINÂMICOS (Prefixos e Regex)

        // §11.2 caminho A — observação de um item específico (card do Editar Carrinho ou lista)
        if (preg_match('/^obs_item_(\d+)$/', $buttonId, $matches)) {
            return $this->cartFlow->promptObservationForItem($phone, (int) $matches[1]);
        }

        // §5.2 — "🔢 Quero mais de um": abre o seletor de quantidade
        if (preg_match('/^flow_qty_([a-z0-9_\-]+)_(\d+)$/', $buttonId, $matches)) {
            return $this->productsHandler->sendQuantityPicker($phone, $matches[1], (int) $matches[2]);
        }

        // §5.2 — quantidade 2-9 escolhida na lista
        if (preg_match('/^flow_qtypick_([a-z0-9_\-]+)_(\d+)_(\d+)$/', $buttonId, $matches)) {
            return $this->cartFlow->startAddProductFlow($phone, $matches[1], $matches[2], max(2, min(9, (int) $matches[3])));
        }

        // §5.2 — "10+": única exceção que pede texto livre
        if (preg_match('/^flow_qty10_([a-z0-9_\-]+)_(\d+)$/', $buttonId, $matches)) {
            $state = $this->flow->getState($phone);
            $state['awaiting_qty'] = ['store_id' => $matches[1], 'product_id' => (int) $matches[2]];
            $this->flow->saveState($phone, $state);

            $this->zapiClient->sendText($phone, 'Digite a quantidade desejada (número):');

            return true;
        }

        // §4 — paginação do carrossel de produtos (geral e por categoria da loja)
        if (preg_match('/^flow_product_more_([a-z0-9_\-]+)_(\d+)$/', $buttonId, $matches)) {
            return $this->productsHandler->sendProductsCarousel($phone, $matches[1], (int) $matches[2]);
        }

        if (preg_match('/^flow_catprod_more_([a-z0-9_\-]+)_([a-z0-9_\-]+)_(\d+)$/', $buttonId, $matches)) {
            return $this->productsHandler->sendProductsCarousel($phone, $matches[1], (int) $matches[3], $matches[2]);
        }

        // §1.1.b/§4 — paginação do carrossel de busca de produto por IA (cruza várias lojas,
        // termo salvo no state porque não cabe de forma segura num id de botão)
        if (preg_match('/^flow_prodsearch_more_(\d+)$/', $buttonId, $matches)) {
            $term = (string) ($this->flow->getState($phone)['product_search_term'] ?? '');

            return $term !== '' && $this->productsHandler->sendProductSearchCarousel($phone, $term, (int) $matches[1]);
        }

        // §1.1.b nível 2 — paginação da busca por categoria geral (fallback quando o título não
        // achou nada), mesma ideia do flow_prodsearch_more_ acima.
        if (preg_match('/^flow_prodsearch_cat_more_(\d+)$/', $buttonId, $matches)) {
            $state = $this->flow->getState($phone);
            $generalCategory = (string) ($state['category_search_general'] ?? '');
            $item = (string) ($state['category_search_item'] ?? '');

            return $generalCategory !== ''
                && $this->productsHandler->sendProductSearchByCategoryCarousel($phone, $generalCategory, $item, (int) $matches[1]);
        }

        // §12.2 — endereço escolhido na lista "Ver Endereços"
        if (preg_match('/^checkout_addr_(\d+)$/', $buttonId, $matches)) {
            return $this->checkoutFlow->handleAddressSelected($phone, (int) $matches[1]);
        }

        // §14.4 — "⭐ Avaliar Pedido" (captura simples de nota 1-5 por texto fica pra depois)
        if (str_starts_with($buttonId, 'rate_order_')) {
            $this->zapiClient->sendText($phone, '⭐ Obrigado! Sua avaliação ajuda demais a loja. Pode responder com uma nota de 1 a 5 e um comentário, se quiser. 💚');

            return true;
        }

        // NOVO PEDIDO: order_new_{id} - Fazer novo pedido (limpa sessão atual)
        if (str_starts_with($buttonId, 'order_new_')) {
            $orderId = (int) str_replace('order_new_', '', $buttonId);

            // Clear current state (including active_order)
            $this->flow->resetState($phone);

            // Optional: mark previous order as abandoned
            $order = Order::find($orderId);
            if ($order && $order->statusValue() === 'pending' && $order->payment_status !== 'paid') {
                $order->update(['status' => 'cancelled', 'rejection_reason' => 'abandoned']);
            }

            // Start fresh flow
            return $this->greetingFlow->sendWelcomePrompt($phone);
        }

        // RETOMAR PAGAMENTO: order_resume_{id} - Reenviar link de pagamento
        if (str_starts_with($buttonId, 'order_resume_')) {
            $orderId = (int) str_replace('order_resume_', '', $buttonId);
            $order = Order::find($orderId);

            if (! $order) {
                $this->zapiClient->sendText($phone, '❌ Pedido não encontrado.');

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
            $message .= '💰 Valor: R$ '.number_format($order->total, 2, ',', '.')."\n\n";
            $message .= 'Clique no botão abaixo para pagar:';

            $storeName = trim((string) ($order->store->name ?? ''));
            $this->zapiClient->sendButtonActions(
                $phone,
                $message,
                [['type' => 'URL', 'url' => $paymentLink, 'label' => '🔗 Abrir link de pagamento']],
                $storeName !== '' ? 'Zapediu & '.$storeName : 'Zapediu'
            );

            return true;
        }

        // VER CARRINHO DO PEDIDO PENDENTE: order_view_{id} (botão do lembrete de pagamento)
        if (str_starts_with($buttonId, 'order_view_')) {
            $orderId = (int) str_replace('order_view_', '', $buttonId);
            $order = Order::find($orderId);

            if (! $order) {
                $this->zapiClient->sendText($phone, '❌ Pedido não encontrado.');

                return true;
            }

            $paymentLink = $this->checkoutFlow->buildPaymentLink(
                $phone,
                $order->store->slug ?? '',
                [],
                (float) $order->total,
                $order->code
            );

            return $this->checkoutFlow->sendPendingOrderMessage($phone, $order, $paymentLink);
        }

        // CANCELAR PEDIDO: order_cancel_{id} - Cancelar pedido pendente
        if (str_starts_with($buttonId, 'order_cancel_')) {
            $orderId = (int) str_replace('order_cancel_', '', $buttonId);
            $order = Order::find($orderId);

            if (! $order) {
                $this->zapiClient->sendText($phone, '❌ Pedido não encontrado.');

                return true;
            }

            if ($order->statusValue() !== 'pending' || $order->payment_status === 'paid') {
                $this->zapiClient->sendText($phone, '❌ Este pedido não pode ser cancelado (já pago ou em andamento).');

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
                return $this->cartFlow->startAddProductFlow($phone, $product->store->slug, (string) $productId);
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

        // Motor de customização (adicionais/tamanho/etc.) — "Quer adicionais?" sim/não
        if (str_starts_with($buttonId, 'flow_custask_')) {
            return $this->cartFlow->confirmWantsCustomizationStep($phone, str_replace('flow_custask_', '', $buttonId), true);
        }

        if (str_starts_with($buttonId, 'flow_custskip_')) {
            return $this->cartFlow->confirmWantsCustomizationStep($phone, str_replace('flow_custskip_', '', $buttonId), false);
        }

        // Motor de customização — seleção de uma opção (flow_custopt_{stepId}_{optionId})
        if (preg_match('/^flow_custopt_(\d+)_(\d+)$/', $buttonId, $matches)) {
            return $this->cartFlow->handleCustomizationOptionSelected($phone, $matches[1], $matches[2]);
        }

        // Fluxo de pizzaria/açaiteria — botão de tamanho no carrossel (flow_pizza_size_{productId}_{variationId})
        if (preg_match('/^flow_pizza_size_(\d+)_(\d+)$/', $buttonId, $matches)) {
            return $this->cartFlow->handlePizzaSizePicked($phone, $matches[1], $matches[2]);
        }

        // "Deseja adicionar mais algum sabor?" sim/não
        if ($buttonId === 'flow_pizza_extra_yes') {
            return $this->cartFlow->handleExtraFlavorAnswer($phone, true);
        }
        if ($buttonId === 'flow_pizza_extra_no') {
            return $this->cartFlow->handleExtraFlavorAnswer($phone, false);
        }

        // Sabor extra escolhido no carrossel (flow_pizza_extra_pick_{productId})
        if (preg_match('/^flow_pizza_extra_pick_(\d+)$/', $buttonId, $matches)) {
            return $this->cartFlow->handleExtraFlavorPicked($phone, $matches[1]);
        }

        // "Deseja adicionar uma borda?" sim/não (flow_pizza_borda_yes|no_{stepId})
        if (preg_match('/^flow_pizza_borda_(yes|no)_(\d+)$/', $buttonId, $matches)) {
            return $this->cartFlow->confirmWantsBordaStep($phone, $matches[2], $matches[1] === 'yes');
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
                'quantity' => (int) $matches[1],
                'store_id' => $matches[2],
                'product_id' => (int) $matches[3],
            ];
        }

        // Padrão simples: flow_add_{storeSlug}_{productId}
        if (preg_match('/^flow_add_([a-z0-9_\-]+)_(\d+)$/', $buttonId, $matches)) {
            return [
                'quantity' => 1,
                'store_id' => $matches[1],
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
