<?php

namespace App\Providers;

use App\Services\EventIngestionService;
use Illuminate\Support\ServiceProvider;
use Laravel\Octane\Facades\Octane;

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
        if ($this->app->bound('octane')) {
            // Every second:
            //  1. Flush any events that have been sitting in the in-process buffer
            //     longer than the flush interval (mirrors Go's batcher ticker goroutine).
            //  2. Drive any Fibers that were suspended during the previous flush so
            //     their ClickHouse inserts complete without blocking the hot path.
            Octane::tick('flush-event-buffer', function () {
                /** @var EventIngestionService $service */
                $service = app(EventIngestionService::class);
                $service->flushBuffer();   // flushes buffer → spawns Fiber if needed
                $service->driveFibers();   // resumes any lingering suspended Fibers
            })->seconds(1);
        }
    }
}
