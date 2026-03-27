<?php

use App\Http\Controllers\Api\AdminEndpointsController;
use App\Http\Controllers\Api\Tenant\AuthController as TenantAuthController;
use App\Http\Controllers\Api\Tenant\CategoryController;
use App\Http\Controllers\Api\Tenant\CompanyController;
use App\Http\Controllers\Api\Tenant\MetricsController;
use App\Http\Controllers\Api\Tenant\OrderController;
use App\Http\Controllers\Api\Tenant\ProductController;
use App\Http\Controllers\Api\Tenant\StoreController;
use App\Http\Controllers\Api\Tenant\WhatsappController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');
Route::post('/auth/login', [TenantAuthController::class, 'login'])->name('api.auth.login');
Route::prefix('admin')->as('admin.')->group(function (): void {
	Route::get('/endpoints', [AdminEndpointsController::class, 'index'])
		->name('endpoints.index');

	Route::post('/endpoints/test-webhook', [AdminEndpointsController::class, 'testWebhook'])
		->name('endpoints.test-webhook');
});
Route::middleware(['auth:sanctum', 'tenant'])->prefix('tenant')->as('api.tenant.')->group(function (): void {
	Route::post('/auth/logout', [TenantAuthController::class, 'logout'])->name('auth.logout');

	Route::get('/company', [CompanyController::class, 'me'])->name('company.me');
	Route::put('/company', [CompanyController::class, 'update'])->middleware('role:owner,manager')->name('company.update');

    // Estoque – Produtos
    Route::apiResource('products', ProductController::class);

    // Lojas
    Route::get('/stores', [StoreController::class, 'index'])->name('stores.index');
    Route::post('/stores', [StoreController::class, 'store'])->middleware('role:owner,manager')->name('stores.store');
    Route::get('/stores/{store}', [StoreController::class, 'show'])->name('stores.show');
    Route::put('/stores/{store}/identity', [StoreController::class, 'updateIdentity'])->middleware('role:owner,manager')->name('stores.identity');
    Route::put('/stores/{store}/address', [StoreController::class, 'updateAddress'])->middleware('role:owner,manager')->name('stores.address');
    Route::put('/stores/{store}/hours', [StoreController::class, 'updateHours'])->middleware('role:owner,manager')->name('stores.hours');

    // Estoque – Categorias
    Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
    Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
    Route::put('/categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
    Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');

    // Analytics / Dashboard
    Route::prefix('analytics')->as('analytics.')->group(function (): void {
        Route::get('/overview',    [MetricsController::class, 'overview'])->name('overview');
        Route::get('/revenue',     [MetricsController::class, 'revenue'])->name('revenue');
        Route::get('/hourly',      [MetricsController::class, 'hourly'])->name('hourly');
        Route::get('/categories',  [MetricsController::class, 'categories'])->name('categories');
        Route::get('/whatsapp',    [MetricsController::class, 'whatsapp'])->name('whatsapp');
    });

    // Pedidos
    Route::get('/orders/summary', [OrderController::class, 'summary'])->name('orders.summary');
    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::post('/orders/{order}/accept', [OrderController::class, 'accept'])->name('orders.accept');
    Route::post('/orders/{order}/reject', [OrderController::class, 'reject'])->name('orders.reject');
    Route::post('/orders/{order}/advance', [OrderController::class, 'advance'])->name('orders.advance');

    // WhatsApp
    Route::post('/orders/{order}/notify-status', [WhatsappController::class, 'notifyOrderStatus'])
        ->middleware('role:owner,manager,operator')
        ->name('orders.notify-status');
});
