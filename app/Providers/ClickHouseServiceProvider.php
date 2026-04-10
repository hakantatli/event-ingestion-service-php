<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\ClickHouseService;

class ClickHouseServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(ClickHouseService::class, function ($app) {
            return new ClickHouseService();
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
