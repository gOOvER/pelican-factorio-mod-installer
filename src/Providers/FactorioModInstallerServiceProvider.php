<?php

namespace gOOvER\FactorioModInstaller\Providers;

use Illuminate\Support\ServiceProvider;
use gOOvER\FactorioModInstaller\Services\FactorioModPortalService;

class FactorioModInstallerServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register the mod portal service as a singleton
        $this->app->singleton(FactorioModPortalService::class, function ($app) {
            return new FactorioModPortalService();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Views and translations are automatically discovered by the plugin system
        // No manual registration needed
    }
}
