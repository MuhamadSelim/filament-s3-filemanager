<?php

namespace MuhamadSelim\FilamentS3Filemanager;

use Illuminate\Support\ServiceProvider;

class FilamentS3FilemanagerServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../../config/filament-s3-filemanager.php',
            'filament-s3-filemanager'
        );
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Publish configuration
        $this->publishes([
            __DIR__.'/../../config/filament-s3-filemanager.php' => config_path('filament-s3-filemanager.php'),
        ], 'filament-s3-filemanager-config');

        // Load views
        $this->loadViewsFrom(
            __DIR__.'/../../resources/views',
            'filament-s3-filemanager'
        );

        // Publish views
        $this->publishes([
            __DIR__.'/../../resources/views' => resource_path('views/vendor/filament-s3-filemanager'),
        ], 'filament-s3-filemanager-views');

        // Register routes
        $this->registerRoutes();
    }

    /**
     * Register package routes.
     */
    protected function registerRoutes(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
    }
}

