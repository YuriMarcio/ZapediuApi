<?php

use App\Http\Controllers\Admin\CourierController;
use App\Http\Controllers\Api\AdminEndpointsController;
use App\Http\Controllers\Api\CommercialDashboardController;
use App\Http\Controllers\Api\DeliveryManagementController;
use App\Http\Controllers\Api\MasterOperationController;
use App\Http\Controllers\Api\MercadoPagoController;
use App\Http\Controllers\Api\OnboardingController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\PublicCheckoutController;
use App\Http\Controllers\Api\SellerController;
use App\Http\Controllers\Api\Tenant\AccountController;
use App\Http\Controllers\Api\Tenant\AuthController as TenantAuthController;
use App\Http\Controllers\Api\Tenant\CategoryController;
use App\Http\Controllers\Api\Tenant\CompanyController;
use App\Http\Controllers\Api\Tenant\LeadController;
use App\Http\Controllers\Api\Tenant\MetricsController;
use App\Http\Controllers\Api\Tenant\OptionalFlowController;
use App\Http\Controllers\Api\Tenant\OrderController;
use App\Http\Controllers\Api\Tenant\PizzaAddonController;
use App\Http\Controllers\Api\Tenant\PizzaConfigController;
use App\Http\Controllers\Api\Tenant\PizzaSizeController;
use App\Http\Controllers\Api\Tenant\ProductController;
use App\Http\Controllers\Api\Tenant\SelectionGroupController;
use App\Http\Controllers\Api\Tenant\StoreController;
use App\Http\Controllers\Api\Tenant\VariationGroupController;
use App\Http\Controllers\Api\Tenant\WalletController;
use App\Http\Controllers\Api\Tenant\WhatsappController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');

Route::prefix('auth')->as('auth.')->group(function (): void {
    Route::post('/login', [TenantAuthController::class, 'login'])->name('login');
    Route::post('/seller/stores', [TenantAuthController::class, 'sellerStores'])->name('seller.stores');
    Route::post('/seller/stores/{store_id}/access', [TenantAuthController::class, 'sellerStoreAccess'])->name('seller.stores.access');
    Route::post('/refresh', [TenantAuthController::class, 'refresh'])->name('refresh');
    Route::middleware('auth:api')->group(function (): void {
        Route::get('/me', [TenantAuthController::class, 'me'])->name('me');
        Route::post('/logout', [TenantAuthController::class, 'logout'])->name('logout');
    });
});

Route::prefix('public')->as('public.')->group(function (): void {
    Route::get('/plans', [PlanController::class, 'index'])->name('plans.index');

    Route::get('/mercadopago/callback', [MercadoPagoController::class, 'handleCallback'])
        ->name('mp.callback');

    Route::post('/seller-codes/validate', [OnboardingController::class, 'validateSellerCode'])->name('seller-codes.validate');

    // Rota sendo usada para registrar entregador momentaneamente (etapa 1 - admin valida e cadastra entregador)
    Route::post('/Registrar/entregador', [CourierController::class, 'store']);
    Route::post('/onboarding/stores', [OnboardingController::class, 'store'])->name('onboarding.stores');
    Route::get('/onboarding/metadata', [OnboardingController::class, 'metadata'])->name('onboarding.metadata');
    Route::post('/checkout/pix', [MercadoPagoController::class, 'createPix']);
    Route::post('/checkout/card', [MercadoPagoController::class, 'createCardPayment']);

    Route::get('/orders/{order:code}/checkout', [PublicCheckoutController::class, 'show'])->name('orders.checkout.show');

    Route::post('/orders/{order:code}/checkout', [PublicCheckoutController::class, 'store'])->name('orders.checkout.store');
});
Route::prefix('admin')->as('admin.')->group(function (): void {
    Route::get('/endpoints', [AdminEndpointsController::class, 'index'])
        ->name('endpoints.index');

    Route::post('/endpoints/test-webhook', [AdminEndpointsController::class, 'testWebhook'])
        ->name('endpoints.test-webhook');

    Route::post('/sellers', [SellerController::class, 'store'])
        ->name('sellers.store');

    // Plans admin CRUD
    Route::get('/plans', [PlanController::class, 'adminIndex'])->name('plans.index');
    Route::post('/plans', [PlanController::class, 'adminStore'])->name('plans.store');
    Route::put('/plans/{plan}', [PlanController::class, 'adminUpdate'])->name('plans.update');
    Route::delete('/plans/{plan}', [PlanController::class, 'adminDestroy'])->name('plans.destroy');
});
Route::middleware(['auth:api', 'tenant'])->prefix('tenant')->as('api.tenant.')->group(function (): void {
    Route::get('/operations/context', [MasterOperationController::class, 'context'])->middleware('role:manager,seller,operator,master');
    Route::get('/company', [CompanyController::class, 'me'])->name('company.me');
    Route::put('/company', [CompanyController::class, 'update'])->middleware('role:owner,manager')->name('company.update');
    Route::put('/account/password', [AccountController::class, 'updatePassword'])->name('account.password');
    Route::put('/company/plan', [CompanyController::class, 'switchPlan'])->middleware('role:seller')->name('company.plan');
    Route::post('/onboarding/stores', [OnboardingController::class, 'storeForManager'])->middleware('role:owner,manager,seller')->name('onboarding.stores');
    Route::get('/sellers', [SellerController::class, 'index'])->middleware('role:manager')->name('sellers.index');
    Route::post('/sellers', [SellerController::class, 'store'])->middleware('role:manager')->name('sellers.store');
    Route::patch('/sellers/{seller}/status', [SellerController::class, 'updateStatus'])->middleware('role:manager')->name('sellers.status');
    Route::get('/commercial/dashboard', [CommercialDashboardController::class, 'show'])->middleware('role:manager,master')->name('commercial.dashboard');
    Route::prefix('delivery')->middleware('role:operator,master')->group(function (): void {
        Route::get('/dashboard', [DeliveryManagementController::class, 'dashboard']);
        Route::get('/couriers', [DeliveryManagementController::class, 'couriers']);
        Route::post('/couriers', [DeliveryManagementController::class, 'storeCourier']);
        Route::patch('/couriers/{courier}', [DeliveryManagementController::class, 'updateCourier']);
        Route::post('/couriers/{courier}/resend-confirmation', [DeliveryManagementController::class, 'resendConfirmation']);
        Route::get('/groups', [DeliveryManagementController::class, 'groups']);
        Route::post('/groups', [DeliveryManagementController::class, 'storeGroup']);
    });
    Route::get('/leads', [LeadController::class, 'index'])->middleware('role:manager,seller')->name('leads.index');
    Route::post('/leads', [LeadController::class, 'store'])->middleware('role:manager,seller')->name('leads.store');
    Route::patch('/leads/{lead}', [LeadController::class, 'update'])->middleware('role:manager,seller')->name('leads.update');
    Route::post('/leads/{lead}/notes', [LeadController::class, 'addNote'])->middleware('role:manager,seller')->name('leads.notes.store');

    // Estoque – Produtos
    Route::apiResource('products', ProductController::class);
    Route::put('/products/{product}/pizza-addons', [ProductController::class, 'updatePizzaAddons'])->name('products.pizza-addons');

    // Lojas
    Route::get('/stores', [StoreController::class, 'index'])->name('stores.index');
    Route::post('/stores', [StoreController::class, 'store'])->middleware('role:owner,manager')->name('stores.store');
    Route::get('/stores/{store}', [StoreController::class, 'show'])->name('stores.show');

    Route::patch('/stores/{store}/identity', [StoreController::class, 'updateIdentity'])->middleware('role:owner,manager,seller')->name('stores.identity');

    Route::patch('/stores/{store}/address', [StoreController::class, 'updateAddress'])->middleware('role:owner,manager,seller')->name('stores.address');

    Route::put('/stores/{store}/hours', [StoreController::class, 'updateHours'])->middleware('role:owner,manager,seller')->name('stores.hours');

    // Estoque – Categorias
    Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
    Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
    Route::put('/categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
    Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');

    // Grupos de selecao
    Route::get('/selection-groups', [SelectionGroupController::class, 'index'])->name('selection-groups.index');
    Route::post('/selection-groups', [SelectionGroupController::class, 'store'])->name('selection-groups.store');
    Route::get('/selection-groups/{selectionGroup}', [SelectionGroupController::class, 'show'])->name('selection-groups.show');
    Route::put('/selection-groups/{selectionGroup}', [SelectionGroupController::class, 'update'])->name('selection-groups.update');
    Route::delete('/selection-groups/{selectionGroup}', [SelectionGroupController::class, 'destroy'])->name('selection-groups.destroy');

    // Variacoes
    Route::get('/variations', [VariationGroupController::class, 'index'])->name('variations.index');
    Route::post('/variations', [VariationGroupController::class, 'store'])->name('variations.store');
    Route::put('/variations/{variation}', [VariationGroupController::class, 'update'])->name('variations.update');
    Route::delete('/variations/{variation}', [VariationGroupController::class, 'destroy'])->name('variations.destroy');

    // Fluxos de opcionais
    Route::get('/flows/catalog', [OptionalFlowController::class, 'catalog'])->name('flows.catalog');
    Route::get('/flows', [OptionalFlowController::class, 'index'])->name('flows.index');
    Route::post('/flows', [OptionalFlowController::class, 'store'])->name('flows.store');
    Route::get('/flows/{flow}', [OptionalFlowController::class, 'show'])->name('flows.show');
    Route::put('/flows/{flow}/configure', [OptionalFlowController::class, 'configure'])->name('flows.configure');
    Route::put('/flows/{flow}', [OptionalFlowController::class, 'update'])->name('flows.update');
    Route::delete('/flows/{flow}', [OptionalFlowController::class, 'destroy'])->name('flows.destroy');

    // Pizzaria — configuração avançada (tamanhos, preço por categoria, bordas/ingredientes/molhos)
    Route::prefix('pizzaria')->as('pizzaria.')->middleware('role:owner,manager,seller')->group(function (): void {
        Route::get('/config', [PizzaConfigController::class, 'show'])->name('config.show');
        Route::put('/config', [PizzaConfigController::class, 'update'])->name('config.update');
        Route::get('/category-prices', [PizzaConfigController::class, 'categoryPrices'])->name('category-prices.index');
        Route::put('/categories/{category}/prices', [PizzaConfigController::class, 'updateCategoryPrices'])->name('category-prices.update');
        Route::get('/review-alerts', [PizzaConfigController::class, 'reviewAlerts'])->name('review-alerts');

        Route::post('/sizes', [PizzaSizeController::class, 'store'])->name('sizes.store');
        Route::patch('/sizes/reorder', [PizzaSizeController::class, 'reorder'])->name('sizes.reorder');
        Route::put('/sizes/{size}', [PizzaSizeController::class, 'update'])->name('sizes.update');
        Route::delete('/sizes/{size}', [PizzaSizeController::class, 'destroy'])->name('sizes.destroy');

        Route::get('/addons', [PizzaAddonController::class, 'index'])->name('addons.index');
        Route::post('/addons', [PizzaAddonController::class, 'store'])->name('addons.store');
        Route::patch('/addons/reorder', [PizzaAddonController::class, 'reorder'])->name('addons.reorder');
        Route::patch('/addons/selection-limit', [PizzaAddonController::class, 'updateSelectionLimit'])->name('addons.selection-limit');
        Route::put('/addons/{option}', [PizzaAddonController::class, 'update'])->name('addons.update');
        Route::delete('/addons/{option}', [PizzaAddonController::class, 'destroy'])->name('addons.destroy');
    });

    // Analytics / Dashboard
    Route::prefix('analytics')->as('analytics.')->group(function (): void {
        Route::get('/overview', [MetricsController::class, 'overview'])->name('overview');
        Route::get('/revenue', [MetricsController::class, 'revenue'])->name('revenue');
        Route::get('/hourly', [MetricsController::class, 'hourly'])->name('hourly');
        Route::get('/categories', [MetricsController::class, 'categories'])->name('categories');
        Route::get('/whatsapp', [MetricsController::class, 'whatsapp'])->name('whatsapp');
    });

    // Pedidos
    Route::get('/orders/summary', [OrderController::class, 'summary'])->name('orders.summary');
    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::post('/orders/{order}/accept', [OrderController::class, 'accept'])->name('orders.accept');
    Route::post('/orders/{order}/reject', [OrderController::class, 'reject'])->name('orders.reject');
    Route::post('/orders/{order}/advance', [OrderController::class, 'advance'])->name('orders.advance');

    // Wallet / antecipacao
    Route::get('/wallet/summary', [WalletController::class, 'summary'])
        ->middleware('role:owner,manager,seller')
        ->name('wallet.summary');
    Route::post('/wallet/advances', [WalletController::class, 'requestAdvance'])
        ->middleware('role:owner,manager,seller')
        ->name('wallet.advances.store');

    // WhatsApp
    Route::post('/orders/{order}/notify-status', [WhatsappController::class, 'notifyOrderStatus'])
        ->middleware('role:owner,manager,operator')
        ->name('orders.notify-status');
});

Route::middleware(['auth:api', 'role:master'])->prefix('master')->as('api.master.')->group(function (): void {
    Route::get('/operations', [MasterOperationController::class, 'index']);
    Route::post('/operations', [MasterOperationController::class, 'store']);
    Route::patch('/operations/{operation}', [MasterOperationController::class, 'update']);
    Route::delete('/operations/{operation}', [MasterOperationController::class, 'destroy']);
    Route::post('/operations/{operation}/session', [MasterOperationController::class, 'connectSession']);
    Route::get('/operations/{operation}/session/qrcode', [MasterOperationController::class, 'sessionQr']);
    Route::get('/operations/{operation}/session/status', [MasterOperationController::class, 'sessionStatus']);
    Route::post('/managers', [MasterOperationController::class, 'createManager']);
});
