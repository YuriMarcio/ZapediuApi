<?php

namespace App\Providers;

use App\Support\Tenancy\TenantContext;
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