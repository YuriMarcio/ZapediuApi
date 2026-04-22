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
use Illuminate\Support\Facades\Log;

// Importe outros Flows necessários (StoreFlow, CartFlow, etc)

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
        Log::info('ButtonHandler::handle', ['phone' => $phone, 'buttonId' => $buttonId]);

        try {
            $result = $this->handleFlowButton($phone, $buttonId);
            Log::info('ButtonHandler::handleFlowButton result', ['result' => $result]);

            if ($result) {
                return true;
            }

            return $this->handleCommerceReplyIntent($phone, $buttonId);
        } catch (\Throwable $e) {
            Log::error('ButtonHandler::handle failed', [
                'buttonId' => $buttonId,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return false;
        }
    }

    /**
     * Grande Switch de Roteamento (Problema 2 e 3 resolvidos aqui)
     */
    private function handleFlowButton(string $phone, string $buttonId): bool
    {
        // IDs Exatos
        switch ($buttonId) {
            case 'btn_ver_lojas':
            case 'flow_back_stores':
                return $this->categoriesHandle->returnToStores($phone);

            case 'btn_ver_categorias':
                return $this->categoriesHandle->sendCategoriesCarousel($phone);

            case 'flow_home':
                // Problema 3: Nome do método corrigido
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
        }

        // Roteamento por Prefixos (Regex)

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

        // Seleção de Loja (Ver Cardápio)
        if (preg_match('/^(flow_store|view_menu)_([a-z0-9_\-]+)$/', $buttonId, $matches)) {
            return $this->storeHandle->selectStore($phone, $matches[2]);
        }

        // Adicionar Produto ao Carrinho
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

    private function handleCategorySearch(string $phone, string $buttonId): bool
    {
        return $this->categoriesHandle->handleCategorySearch($phone, $buttonId);
    }

    private function handleCommerceReplyIntent(string $phone, string $buttonId): bool
    {
        // Log para debug de botões não mapeados
        Log::info("Botão não mapeado recebido: {$buttonId} de {$phone}");
        return false;
    }

    private function resolveAddButtonPayload(string $buttonId): ?array
    {
        if (preg_match('/^flow_add([123])_([a-z0-9_\-]+)_(\d+)$/', $buttonId, $matches)) {
            return [
                'store_id'  => $matches[2],
                'product_id' => $matches[3],
                'quantity'  => (int) $matches[1],
            ];
        }

        if (preg_match('/^flow_add_([a-z0-9_\-]+)_(\d+)$/', $buttonId, $matches)) {
            return [
                'store_id'  => $matches[1],
                'product_id' => $matches[2],
                'quantity'  => 1,
            ];
        }

        return null;
    }
    private function saveFlowState(string $phone, array $state): void
    {
        $this->flow->saveState($phone, $state);
    }
    private function handleViewStores(string $phone): bool
    {
        // Aqui você chamaria o StoreFlow->sendPage($phone, 0);
        return true;
    }

    private function handleSelectStore(string $phone, string $storeSlug): bool
    {
        // Lógica de selecionar a loja e salvar no estado
        return true;
    }
}
