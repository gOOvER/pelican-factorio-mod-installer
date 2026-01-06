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

        // Merge plugin config
        $this->mergeConfigFrom(
            plugin_path('factorio-mod-installer', 'config/factorio-mod-installer.php'),
            'factorio-mod-installer'
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Load routes with correct middleware
        \Illuminate\Support\Facades\Route::middleware(['api', 'client-api', 'throttle:api.client'])
            ->prefix('api/client')
            ->group(plugin_path('factorio-mod-installer', 'src/routes.php'));

        // Load translations
        $this->loadTranslationsFrom(
            plugin_path('factorio-mod-installer', 'lang'),
            'factorio-mod-installer'
        );

        // Load views
        $this->loadViewsFrom(
            plugin_path('factorio-mod-installer', 'resources/views'),
            'factorio-mod-installer'
        );

        // Set fallback locale for plugin translations
        $this->app['translator']->addNamespace('factorio-mod-installer-fallback', plugin_path('factorio-mod-installer', 'lang'));
        
        // Register a custom translation loader that falls back to English
        $this->app->resolving('translator', function ($translator) {
            $originalGet = $translator->get(...);
            
            // Extend translator to fallback to English for this plugin
            $translator->macro('getWithFallback', function ($key, array $replace = [], $locale = null) use ($translator, $originalGet) {
                if (strpos($key, 'factorio-mod-installer::') === 0) {
                    $locale = $locale ?: app()->getLocale();
                    $result = $translator->get($key, $replace, $locale);
                    
                    // If translation not found and locale is not English, try English
                    if ($result === $key && $locale !== 'en') {
                        $result = $translator->get($key, $replace, 'en');
                    }
                    
                    return $result;
                }
                
                return $originalGet($key, $replace, $locale);
            });
        });
    }
}
