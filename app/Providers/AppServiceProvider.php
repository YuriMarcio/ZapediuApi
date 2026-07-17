<?php

namespace App\Providers;

use App\Support\Tenancy\TenantContext;
use App\Services\Whatsapp\WhatsAppClientInterface;
use App\Services\Whatsapp\FlowBridgeClient;
use App\Services\Zapi\ZapiClient;
use Illuminate\Support\ServiceProvider;
use App\Models\Order; // <-- IMPORTANTE
use App\Observers\OrderObserver; // <-- IMPORTANTE

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantContext::class, fn (): TenantContext => new TenantContext());

        // Troca de provider de WhatsApp via WHATSAPP_PROVIDER — ver config/services.php.
        $this->app->bind(WhatsAppClientInterface::class, function ($app) {
            return config('services.whatsapp.provider') === 'flowbridge'
                ? $app->make(FlowBridgeClient::class)
                : $app->make(ZapiClient::class);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 👉 O gatilho blindado!
        Order::observe(OrderObserver::class);
    }
}