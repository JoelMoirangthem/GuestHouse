<?php

namespace App\Providers;

use App\Application\Services\SettingsRegistry;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Administrator-saved settings override the .env defaults (SettingsRegistry).
        SettingsRegistry::applyToConfig();
    }
}
