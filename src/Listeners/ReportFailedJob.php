<?php

namespace Queuewatch\Laravel\Listeners;

use Illuminate\Queue\Events\JobFailed;
use Queuewatch\Laravel\Jobs\SendFailureReport;

class ReportFailedJob
{
    public function handle(JobFailed $event): void
    {
        if (! $this->shouldReport($event)) {
            return;
        }

        $payload = $this->buildPayload($event);

        $this->dispatchReport($payload);
    }

    protected function shouldReport(JobFailed $event): bool
    {
        // Check if agent is enabled
        if (! config('queuewatch.enabled', true)) {
            return false;
        }

        // Check if API key is configured
        if (empty(config('queuewatch.api_key'))) {
            return false;
        }

        // Never report failures of the failure reporter itself (avoid infinite loops)
        $jobClass = $this->getJobClass($event);
        if ($jobClass === SendFailureReport::class) {
            return false;
        }

        // Check if job is in ignored list
        if (in_array($jobClass, config('queuewatch.ignored_jobs', []))) {
            return false;
        }

        // Check if queue is in ignored list
        $queue = $event->job->getQueue();
        if (in_array($queue, config('queuewatch.ignored_queues', []))) {
            return false;
        }

        // Check if exception is in ignored list
        $exceptionClass = get_class($event->exception);
        if (in_array($exceptionClass, config('queuewatch.ignored_exceptions', []))) {
            return false;
        }

        return true;
    }

    protected function buildPayload(JobFailed $event): array
    {
        $job = $event->job;
        $exception = $event->exception;

        $payload = [
            'project' => config('queuewatch.project', config('app.name')),
            'environment' => config('queuewatch.environment', config('app.env')),
            'job' => [
                'id' => $job->getJobId(),
                'uuid' => $job->uuid(),
                'name' => $job->resolveName(),
                'class' => $this->getJobClass($event),
                'queue' => $job->getQueue(),
                'connection' => $event->connectionName,
                'attempts' => $job->attempts(),
                'max_tries' => $job->maxTries(),
                'timeout' => $job->timeout(),
            ],
            'exception' => [
                'class' => get_class($exception),
                'message' => $exception->getMessage(),
                'code' => $exception->getCode(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $this->formatTrace($exception),
            ],
            'failed_at' => now()->toIso8601String(),
            'server' => [
                'hostname' => gethostname(),
                'php_version' => PHP_VERSION,
                'laravel_version' => app()->version(),
            ],
        ];

        // Optionally include job payload
        if (config('queuewatch.collect_job_data', true)) {
            $payload['job']['payload'] = $this->getJobPayload($job);
        }

        return $payload;
    }

    protected function getJobClass(JobFailed $event): string
    {
        $job = $event->job;

        // Try to get the actual job class name
        $payload = $job->payload();

        return $payload['displayName'] ?? $payload['job'] ?? $job->resolveName();
    }

    protected function getJobPayload($job): ?array
    {
        try {
            $payload = $job->payload();

            // Remove serialized command unless retry is enabled (retry needs the command to reconstruct the job)
            if (! config('queuewatch.retry.enabled', false)) {
                unset($payload['data']['command']);
            }

            return $payload;
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function formatTrace(\Throwable $exception): array
    {
        $trace = $exception->getTrace();

        // Limit trace to 20 frames to keep payload size reasonable
        $trace = array_slice($trace, 0, 20);

        return array_map(function ($frame) {
            return [
                'file' => $frame['file'] ?? null,
                'line' => $frame['line'] ?? null,
                'function' => $frame['function'] ?? null,
                'class' => $frame['class'] ?? null,
            ];
        }, $trace);
    }

    protected function dispatchReport(array $payload): void
    {
        $connection = config('queuewatch.queue_connection');
        $queue = config('queuewatch.queue', 'default');

        if ($queue === 'sync') {
            SendFailureReport::dispatchSync($payload);

            return;
        }

        $job = new SendFailureReport($payload);

        if ($connection) {
            $job->onConnection($connection);
        }

        $job->onQueue($queue);

        dispatch($job);
    }
}
