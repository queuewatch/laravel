<?php

namespace Queuewatch\Laravel;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Queuewatch\Laravel\Api\QueuewatchClient;
use Queuewatch\Laravel\Commands\ListFailedCommand;
use Queuewatch\Laravel\Commands\QueuewatchTestCommand;
use Queuewatch\Laravel\Http\Controllers\RetryController;
use Queuewatch\Laravel\Listeners\ReportFailedJob;

class QueuewatchServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ListFailedCommand::class,
                QueuewatchTestCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/queuewatch.php' => config_path('queuewatch.php'),
            ], 'queuewatch-config');
        }

        $this->registerFailedJobListener();
        $this->registerRetryRoute();
    }

    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/queuewatch.php',
            'queuewatch'
        );

        $this->app->singleton(QueuewatchClient::class, function ($app) {
            return new QueuewatchClient(
                config('queuewatch.api_key'),
                config('queuewatch.endpoint'),
                config('queuewatch.timeout')
            );
        });
    }

    protected function registerFailedJobListener(): void
    {
        if (! config('queuewatch.enabled', true)) {
            return;
        }

        if (empty(config('queuewatch.api_key'))) {
            return;
        }

        Event::listen(JobFailed::class, ReportFailedJob::class);
    }

    protected function registerRetryRoute(): void
    {
        if (! config('queuewatch.retry.enabled', false)) {
            return;
        }

        Route::post(config('queuewatch.retry.path', 'queuewatch/retry'), RetryController::class)
            ->middleware(config('queuewatch.retry.middleware', []))
            ->name('queuewatch.retry');
    }
}
