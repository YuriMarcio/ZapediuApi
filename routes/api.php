<?php

use App\Http\Controllers\Api\PaymentWebhookController;
use App\Http\Controllers\Api\Tenant\AuthController as TenantAuthController;
use App\Http\Controllers\Api\Tenant\CompanyController;
use App\Http\Controllers\Api\Tenant\MetricsController;
use App\Http\Controllers\Api\Tenant\ProductController;
use App\Http\Controllers\Api\Tenant\WhatsappController;
use App\Http\Controllers\Api\ZapiWebhookController;
use Illuminate\Support\Facades\Route;



Route::post('/webhooks/zapi', ZapiWebhookController::class)->name('api.webhooks.zapi');
Route::post('/webhooks/payment', PaymentWebhookController::class)->name('api.webhooks.payment');


Route::post('/{any?}', ZapiWebhookController::class)
	->where('any', '.*')
	->name('api.root');
