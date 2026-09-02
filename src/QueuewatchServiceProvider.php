<?php

namespace Queuewatch\Laravel;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStarting;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Queuewatch\Laravel\Api\QueuewatchClient;
use Queuewatch\Laravel\Commands\FlushWorkerHeartbeatsCommand;
use Queuewatch\Laravel\Commands\ListFailedCommand;
use Queuewatch\Laravel\Commands\QueuewatchTestCommand;
use Queuewatch\Laravel\Http\Controllers\RetryController;
use Queuewatch\Laravel\Listeners\ReportFailedJob;
use Queuewatch\Laravel\Listeners\TrackWorkerLifecycle;
use Queuewatch\Laravel\Workers\WorkerReporter;

class QueuewatchServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ListFailedCommand::class,
                QueuewatchTestCommand::class,
                FlushWorkerHeartbeatsCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/queuewatch.php' => config_path('queuewatch.php'),
            ], 'queuewatch-config');
        }

        $this->registerFailedJobListener();
        $this->registerRetryRoute();
        $this->registerWorkerListeners();
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

        $this->app->singleton(WorkerReporter::class);
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

    /**
     * Register the queue worker lifecycle listeners and the scheduled
     * heartbeat flush.
     *
     * Opt-in: nothing here runs unless worker monitoring is enabled and
     * an api key is configured, so installing this package introduces no
     * new behaviour until a customer explicitly turns it on.
     *
     * Every event is guarded with class_exists() because WorkerStarting,
     * Looping, JobProcessed, JobFailed and WorkerStopping are not all
     * present across the Laravel 10-13 versions this package supports.
     * Registering a listener for an event class the framework doesn't
     * ship would fatal at boot.
     */
    protected function registerWorkerListeners(): void
    {
        if (! config('queuewatch.workers.enabled', false) || empty(config('queuewatch.api_key'))) {
            return;
        }

        if (! $this->app->make(WorkerReporter::class)->hasUsableStore()) {
            Log::warning('Queuewatch worker monitoring needs a shared, persistent cache store; heartbeats will be lost with the current driver.');
        }

        $listeners = [
            WorkerStarting::class => 'handleStarting',
            Looping::class => 'handleLooping',
            JobProcessed::class => 'handleJobProcessed',
            JobFailed::class => 'handleJobProcessed',
            WorkerStopping::class => 'handleStopping',
        ];

        foreach ($listeners as $event => $method) {
            if (! class_exists($event)) {
                continue;
            }

            Event::listen($event, [TrackWorkerLifecycle::class, $method]);
        }

        if ($this->app->runningInConsole()) {
            $this->app->booted(function (): void {
                $this->app->make(Schedule::class)
                    ->command('queuewatch:workers:flush')
                    ->everyMinute()
                    ->withoutOverlapping();
            });
        }
    }
}
