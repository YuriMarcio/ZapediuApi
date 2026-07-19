<?php

use App\Http\Controllers\Api\FlowBridgeWebhookController;
use App\Http\Controllers\Api\PaymentWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/payment', PaymentWebhookController::class)->name('api.webhooks.payment');
Route::post('/webhooks/flowbridge/{token}', FlowBridgeWebhookController::class)->name('api.webhooks.flowbridge');

