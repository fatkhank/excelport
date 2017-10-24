<?php

namespace Hamba\ExcelPort;

use Illuminate\Support\ServiceProvider;

class ExcelPortServiceProvider extends ServiceProvider
{
    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = false;

    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->loadRoutesFrom(__DIR__.'/routes.php');
        $this->loadMigrationsFrom(__DIR__.'/migrations');
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__.'/config/xltools.php', 'xltools'
        );

        $this->app->singleton(ImportManager::class, function($app){
            return new ImportManager;
        });

        $this->app->singleton(ExportManager::class, function($app){
            return new ExportManager;
        });
    }
}
