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
        //
    }
}
