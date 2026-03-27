<?php

use App\Http\Controllers\Api\AdminEndpointsController;
use App\Http\Controllers\Api\ZapiWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/admin/endpoints', [AdminEndpointsController::class, 'index'])
	->name('api.admin.endpoints.index');

Route::post('/admin/endpoints/test-webhook', [AdminEndpointsController::class, 'testWebhook'])
	->name('api.admin.endpoints.test-webhook');

Route::post('/{any?}', ZapiWebhookController::class)
	->where('any', '.*')
	->name('api.root');
