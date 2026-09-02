<?php

namespace Queuewatch\Laravel\Listeners;

use Illuminate\Support\Facades\Log;
use Queuewatch\Laravel\Api\QueuewatchClient;
use Queuewatch\Laravel\Queuewatch;
use Queuewatch\Laravel\Workers\WorkerReporter;

class TrackWorkerLifecycle
{
    public function __construct(
        protected WorkerReporter $reporter,
        protected QueuewatchClient $client
    ) {}

    /**
     * Handle the queue worker starting event.
     *
     * Deliberately untyped: the concrete event class is only guaranteed to
     * exist when Illuminate\Queue\Events\WorkerStarting is present, and the
     * listener is registered by the service provider only after a
     * class_exists() check for the currently installed Laravel version.
     */
    public function handleStarting($event): void
    {
        if (! $this->enabled()) {
            return;
        }

        $options = $event->workerOptions ?? $event->options ?? null;

        $this->reporter->startRun($event->connectionName, $event->queue, $options);

        $this->send(fn () => $this->client->startWorkerRun([
            'run_id' => $this->reporter->runId(),
            'environment' => config('queuewatch.environment', config('app.env')),
            'name' => $options->name ?? null,
            'hostname' => gethostname(),
            'pid' => getmypid(),
            'connection' => $event->connectionName,
            'queues' => array_values(array_filter(explode(',', (string) $event->queue))),
            'options' => [
                'memory' => $options->memory ?? null,
                'timeout' => $options->timeout ?? null,
                'max_jobs' => $options->maxJobs ?? null,
                'max_time' => $options->maxTime ?? null,
                'sleep' => $options->sleep ?? null,
                'max_tries' => $options->maxTries ?? null,
                'backoff' => $options->backoff ?? null,
            ],
            'heartbeat_interval' => (int) config('queuewatch.workers.heartbeat_interval', 15),
            'laravel_version' => app()->version(),
            'php_version' => PHP_VERSION,
            'package_version' => Queuewatch::version(),
            'started_at' => now()->toIso8601String(),
        ]));
    }

    /**
     * Handle the queue worker looping event.
     *
     * Fires on every iteration of the worker loop, so this must stay
     * cheap: the throttle check happens before any other work, and this
     * method never performs HTTP or queue dispatch — only an in-memory
     * buffer write.
     *
     * Deliberately untyped for the same reason as handleStarting().
     */
    public function handleLooping($event): void
    {
        if (! $this->enabled() || ! $this->reporter->shouldHeartbeat()) {
            return;
        }

        $this->reporter->bufferHeartbeat((int) round(memory_get_usage(true) / 1024 / 1024));
    }

    /**
     * Handle the queue job processed/failed event.
     *
     * Registered for both JobProcessed and JobFailed, whose payloads
     * differ, so $event is never inspected here.
     */
    public function handleJobProcessed($event): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->reporter->recordJobProcessed();
    }

    /**
     * Handle the queue worker stopping event.
     *
     * Sent synchronously: the worker process is exiting, so there is no
     * later opportunity to report this. WorkerStopReason (and the
     * jobsProcessed, memoryUsage, lastJobProcessedAt properties that
     * arrived alongside it) only exist on Laravel 13.30+, so every one of
     * them is read through property_exists() and the reason is always
     * accessed with the null-safe operator — never $reason->value.
     *
     * Deliberately untyped for the same reason as handleStarting().
     */
    public function handleStopping($event): void
    {
        $runId = $this->reporter->runId();

        if (! $this->enabled() || $runId === null) {
            return;
        }

        $reason = property_exists($event, 'reason') ? $event->reason : null;
        $jobsProcessed = property_exists($event, 'jobsProcessed') ? $event->jobsProcessed : null;
        $memoryUsage = property_exists($event, 'memoryUsage') ? $event->memoryUsage : null;
        $lastJobProcessedAt = property_exists($event, 'lastJobProcessedAt') ? $event->lastJobProcessedAt : null;

        $this->send(fn () => $this->client->stopWorkerRun($runId, [
            'reason' => $reason?->value,
            'reason_description' => $reason?->description(),
            'status' => (int) ($event->status ?? 0),
            'jobs_processed' => $jobsProcessed ?? $this->reporter->jobsProcessed(),
            'memory_mb' => $memoryUsage !== null ? (int) round($memoryUsage) : null,
            'last_job_processed_at' => $lastJobProcessedAt !== null
                ? now()->setTimestamp((int) $lastJobProcessedAt)->toIso8601String()
                : null,
            'stopped_at' => now()->toIso8601String(),
        ]));

        $this->reporter->clearRun();
    }

    /**
     * Whether worker monitoring is enabled and configured to report.
     *
     * Shared by every lifecycle handler (start, heartbeat, stop) added in
     * later tasks — nothing may be sent to the QueueWatch API unless both
     * conditions hold.
     */
    protected function enabled(): bool
    {
        return (bool) config('queuewatch.workers.enabled', false)
            && ! empty(config('queuewatch.api_key'));
    }

    /**
     * Run $callback, swallowing any transport failure.
     *
     * Shared by every lifecycle handler — a monitoring agent must never
     * let a QueueWatch API failure break the worker it is monitoring.
     */
    protected function send(callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            Log::debug('Queuewatch worker report failed', ['error' => $e->getMessage()]);
        }
    }
}
