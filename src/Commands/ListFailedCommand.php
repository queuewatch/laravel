<?php

namespace Queuewatch\Laravel\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputOption;

class ListFailedCommand extends Command
{
    protected $name = 'queue:failed';

    protected $description = 'List all of the failed queue jobs';

    public function handle(): int
    {
        $failer = $this->laravel['queue.failer'];

        if (! method_exists($failer, 'all')) {
            $this->error('Queue failer does not support listing all failed jobs.');

            return 1;
        }

        $jobs = collect($failer->all());

        if ($jobs->isEmpty()) {
            if ($this->option('json')) {
                $this->line(json_encode([
                    'failed_jobs' => [],
                    'count' => 0,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return 0;
            }

            $this->info('No failed jobs!');

            return 0;
        }

        $jobs = $this->applyFilters($jobs);

        if ($jobs->isEmpty()) {
            if ($this->option('json')) {
                $this->line(json_encode([
                    'failed_jobs' => [],
                    'count' => 0,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return 0;
            }

            $this->info('No failed jobs match the given criteria.');

            return 0;
        }

        if ($limit = $this->option('limit')) {
            $jobs = $jobs->take((int) $limit);
        }

        if ($this->option('json')) {
            return $this->outputJson($jobs);
        }

        return $this->displayTable($jobs);
    }

    protected function applyFilters($jobs)
    {
        if ($queue = $this->option('queue')) {
            $jobs = $jobs->filter(fn ($job) => $job->queue === $queue);
        }

        if ($connection = $this->option('connection')) {
            $jobs = $jobs->filter(fn ($job) => $job->connection === $connection);
        }

        if ($after = $this->option('after')) {
            try {
                $afterDate = Carbon::parse($after);
                $jobs = $jobs->filter(function ($job) use ($afterDate) {
                    $failedAt = $job->failed_at instanceof Carbon
                        ? $job->failed_at
                        : Carbon::parse($job->failed_at);

                    return $failedAt->gte($afterDate);
                });
            } catch (\Exception $e) {
                $this->error("Invalid date format for --after: {$after}");

                return collect();
            }
        }

        if ($before = $this->option('before')) {
            try {
                $beforeDate = Carbon::parse($before);
                $jobs = $jobs->filter(function ($job) use ($beforeDate) {
                    $failedAt = $job->failed_at instanceof Carbon
                        ? $job->failed_at
                        : Carbon::parse($job->failed_at);

                    return $failedAt->lte($beforeDate);
                });
            } catch (\Exception $e) {
                $this->error("Invalid date format for --before: {$before}");

                return collect();
            }
        }

        if ($class = $this->option('class')) {
            $jobs = $jobs->filter(function ($job) use ($class) {
                $payload = json_decode($job->payload ?? '{}', true);
                $jobClass = $payload['displayName'] ?? $payload['job'] ?? null;

                return $jobClass && str_contains($jobClass, $class);
            });
        }

        return $jobs;
    }

    protected function outputJson($jobs): int
    {
        $output = [
            'failed_jobs' => $jobs->map(function ($job) {
                return [
                    'id' => $job->id,
                    'uuid' => $job->uuid ?? null,
                    'connection' => $job->connection,
                    'queue' => $job->queue,
                    'payload' => json_decode($job->payload ?? '{}', true),
                    'exception' => $job->exception,
                    'failed_at' => $this->formatFailedAt($job->failed_at),
                ];
            })->values()->all(),
            'count' => $jobs->count(),
        ];

        $this->line(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return 0;
    }

    protected function displayTable($jobs): int
    {
        $this->table(
            ['ID', 'Connection', 'Queue', 'Class', 'Failed At'],
            $jobs->map(function ($job) {
                $payload = json_decode($job->payload ?? '{}', true);

                return [
                    'id' => $job->id,
                    'connection' => $job->connection,
                    'queue' => $job->queue,
                    'class' => $this->formatJobClass($payload['displayName'] ?? $payload['job'] ?? 'N/A'),
                    'failed_at' => $this->formatFailedAt($job->failed_at),
                ];
            })->all()
        );

        $this->newLine();
        $this->info("Total failed jobs: {$jobs->count()}");

        return 0;
    }

    protected function formatJobClass($class): string
    {
        if (empty($class) || $class === 'N/A') {
            return 'N/A';
        }

        $parts = explode('\\', $class);

        return end($parts);
    }

    protected function formatFailedAt($failedAt): string
    {
        if ($failedAt instanceof Carbon) {
            return $failedAt->toDateTimeString();
        }

        try {
            return Carbon::parse($failedAt)->toDateTimeString();
        } catch (\Exception $e) {
            return (string) $failedAt;
        }
    }

    protected function getOptions(): array
    {
        return [
            ['json', null, InputOption::VALUE_NONE, 'Output the failed jobs as JSON'],
            ['queue', null, InputOption::VALUE_OPTIONAL, 'Filter by queue name'],
            ['connection', null, InputOption::VALUE_OPTIONAL, 'Filter by connection name'],
            ['after', null, InputOption::VALUE_OPTIONAL, 'Show jobs failed after date (e.g., "2025-11-20" or "yesterday")'],
            ['before', null, InputOption::VALUE_OPTIONAL, 'Show jobs failed before date (e.g., "2025-11-21" or "today")'],
            ['class', null, InputOption::VALUE_OPTIONAL, 'Filter by job class name (partial match supported)'],
            ['limit', null, InputOption::VALUE_OPTIONAL, 'Limit the number of results'],
        ];
    }
}
