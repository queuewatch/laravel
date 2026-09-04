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
     *
     * The run id is minted locally before the registration request is
     * even sent, so a failed registration — a non-2xx response or a
     * thrown transport exception — always clears it again. Otherwise the
     * worker would keep a run id no one on the dashboard has ever heard
     * of: invisible, yet still buffering heartbeats and 404ing its
     * eventual stop report for the rest of the process's life.
     */
    public function handleStarting($event): void
    {
        if (! $this->enabled()) {
            return;
        }

        $options = $event->workerOptions ?? $event->options ?? null;

        $this->reporter->startRun($event->connectionName, $event->queue, $options);

        $this->send(
            function () use ($options, $event): void {
                $response = $this->client->startWorkerRun([
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
                    'worker_type' => $this->workerType(),
                    'heartbeat_interval' => (int) config('queuewatch.workers.heartbeat_interval', 15),
                    'laravel_version' => app()->version(),
                    'php_version' => PHP_VERSION,
                    'package_version' => Queuewatch::version(),
                    'started_at' => now()->toIso8601String(),
                ]);

                if (in_array($response->status(), [403, 429], true)) {
                    $this->reporter->backOff();
                }

                if (! $response->successful()) {
                    $this->reporter->clearRun();
                }
            },
            onFailure: fn () => $this->reporter->clearRun(),
        );
    }

    /**
     * Handle the queue worker looping event.
     *
     * Fires on every iteration of the worker loop, so this must stay
     * cheap: the in-memory shouldHeartbeat() throttle is checked first,
     * short-circuiting before enabled() ever runs. enabled() calls
     * isBackedOff(), a cache round trip, so evaluating it on every loop
     * — rather than only once per heartbeat interval — would turn a
     * worker processing dozens of jobs a second into dozens of cache
     * reads a second from an agent meant to be invisible.
     *
     * Deliberately untyped for the same reason as handleStarting().
     */
    public function handleLooping($event): void
    {
        if (! $this->reporter->shouldHeartbeat()) {
            return;
        }

        if (! $this->enabled()) {
            $this->reporter->deferHeartbeat();

            return;
        }

        $this->reporter->bufferHeartbeat((int) round(memory_get_usage(true) / 1024 / 1024));
    }

    /**
     * Handle the queue job processed/failed event.
     *
     * Registered for both JobProcessed and JobFailed, whose payloads
     * differ, so $event is never inspected here.
     *
     * Deliberately checks configuredToReport() rather than enabled():
     * a backoff should stop the package from *sending* data, not from
     * *counting* jobs — the count is cheap, in-memory, and needed so a
     * heartbeat sent once the backoff lifts still reports an accurate
     * total.
     */
    public function handleJobProcessed($event): void
    {
        if (! $this->configuredToReport()) {
            return;
        }

        $this->reporter->recordJobProcessed();
    }

    /**
     * Handle the queue worker stopping event.
     *
     * Sent synchronously: the worker process is exiting, so there is no
     * later opportunity to report this. WorkerStopReason and
     * WorkerStopping::$reason exist from Laravel 12.20 onward, but the
     * richer payload — jobsProcessed, memoryUsage, lastJobProcessedAt —
     * was only added in 13.30, so every one of those three is read
     * through property_exists(). The reason is always accessed with the
     * null-safe operator and only via ->value: some 12.x releases carry
     * a populated $reason whose WorkerStopReason enum has no
     * description() method, so that method is never called here — the
     * description is derived server-side from the reason value instead.
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
     * Whether worker monitoring is configured to report at all.
     *
     * Unlike enabled(), this does not consult isBackedOff() — it is for
     * callers whose work should continue through a backoff (recording a
     * job count locally costs nothing) as opposed to callers that would
     * actually send something to the API.
     */
    protected function configuredToReport(): bool
    {
        return (bool) config('queuewatch.workers.enabled', false)
            && ! empty(config('queuewatch.api_key'));
    }

    /**
     * Whether worker monitoring is enabled and configured to report.
     *
     * Shared by every lifecycle handler that sends data — nothing may be
     * sent to the QueueWatch API unless all three conditions hold:
     * monitoring is enabled, an api key is configured, and the host is
     * not currently backed off.
     */
    /**
     * Which kind of process is running this worker.
     *
     * Horizon manages its workers through `horizon:work`, supervisor and manual
     * setups use `queue:work`, and `queue:listen` is a third shape entirely. The
     * dashboard shows this so an operator can tell at a glance whether a pool is
     * Horizon-managed — which matters, because Horizon never passes --name and
     * restarts workers far more aggressively than supervisor does.
     */
    protected function workerType(): string
    {
        $command = $_SERVER['argv'][1] ?? null;

        return match ($command) {
            'horizon:work' => 'horizon',
            'queue:work' => 'queue:work',
            'queue:listen' => 'queue:listen',
            default => 'other',
        };
    }

    protected function enabled(): bool
    {
        return $this->configuredToReport() && ! $this->reporter->isBackedOff();
    }

    /**
     * Run $callback, swallowing any transport failure.
     *
     * Shared by every lifecycle handler — a monitoring agent must never
     * let a QueueWatch API failure break the worker it is monitoring.
     * $onFailure, when given, runs after a caught exception so a caller
     * can still clean up its own state (e.g. clearing a run id) even
     * though the failure itself is swallowed.
     */
    protected function send(callable $callback, ?callable $onFailure = null): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            Log::debug('Queuewatch worker report failed', ['error' => $e->getMessage()]);

            if ($onFailure !== null) {
                $onFailure();
            }
        }
    }
}
