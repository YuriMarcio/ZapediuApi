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

    private function handleFlowButton(string $phone, string $buttonId): bool
    {
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
