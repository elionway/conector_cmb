<?php

namespace App\Providers;

use App\Services\Erp;
use App\Services\Opencart;
use App\Services\Sexp;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(\App\Services\ConnectorAlertReporter::class, function ($app) {
            return new \App\Services\ConnectorAlertReporter(
                (array) $app['config']->get('connector_alerts', []),
                storage_path('framework/connector-alerts'), new \Illuminate\Http\Client\Factory()
            );
        });

        $this->app->bind('opencart', function($app){
            return new Opencart();
        });

        $this->app->bind('sexp', function($app){
            return new Sexp();
        });

        $this->app->bind('erp', function($app){
            return new Erp();
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->app['events']->listen(\Illuminate\Console\Events\CommandStarting::class, function ($event) {
            $this->app->make(\App\Services\ConnectorAlertReporter::class)->setCommand($event->command);
        });
        $this->app['events']->listen(\Illuminate\Console\Events\CommandFinished::class, function ($event) {
            if (in_array($event->command, \App\Services\ConnectorAlertReporter::COMMANDS, true)) {
                $this->app->make(\App\Services\ConnectorAlertReporter::class)->flush(3);
            }
        });
    }
}
