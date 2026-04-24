<?php

use App\Http\Controllers\Api\PaymentWebhookController;
use App\Http\Controllers\Api\ZapiWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhook', ZapiWebhookController::class)->name('api.webhooks.zapi');
Route::post('/webhooks/payment', PaymentWebhookController::class)->name('api.webhooks.payment');

Route::post('/{any?}', ZapiWebhookController::class)
	->where('any', '.*')
	->name('api.root');
