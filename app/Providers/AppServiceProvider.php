<?php

namespace App\Providers;

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
        // Load backend routes for each enabled plugin that ships a backend/routes.php
        if (\Schema::hasTable('plugins')) {
            $slugs = \DB::table('plugins')->where('is_enabled', true)->pluck('slug');
            foreach ($slugs as $slug) {
                $pluginPath = storage_path('app/public/plugins/' . $slug . '/backend');
                $routesFile = $pluginPath . '/routes.php';
                if (file_exists($routesFile)) {
                    require $routesFile;
                }
            }
        }
    }
}
