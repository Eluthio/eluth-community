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
        // Load backend routes for each enabled plugin that ships a backend/routes.php.
        // Wrapped in try/catch because this runs during composer package:discover
        // (and other artisan bootstrap steps) where no database connection exists yet.
        try {
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
        } catch (\Throwable) {
            // DB unavailable (CI, fresh install, package discovery) — skip silently
        }
    }
}
