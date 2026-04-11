<?php

use App\Http\Controllers\Api\PaymentWebhookController;
use App\Http\Controllers\Api\ZapiWebhookController;
use App\Http\Controllers\Api\MercadoPagoController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/zapi', ZapiWebhookController::class)->name('api.webhooks.zapi');
Route::post('/webhooks/payment', PaymentWebhookController::class)->name('api.webhooks.payment');

// Mercado Pago endpoints


Route::post('/{any?}', ZapiWebhookController::class)
	->where('any', '.*')
	->name('api.root');
