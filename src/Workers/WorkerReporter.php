<?php

namespace Queuewatch\Laravel\Workers;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WorkerReporter
{
    public const BUFFER_KEY = 'queuewatch:worker-heartbeats';

    public const BACKOFF_KEY = 'queuewatch:worker-backoff';

    protected ?string $runId = null;

    protected int $jobsProcessed = 0;

    protected ?float $lastHeartbeatAt = null;

    public function startRun(string $connectionName, ?string $queue, $options): void
    {
        $this->runId = (string) Str::uuid();
        $this->jobsProcessed = 0;
        $this->lastHeartbeatAt = null;
    }

    public function runId(): ?string
    {
        return $this->runId;
    }

    public function clearRun(): void
    {
        $this->runId = null;
        $this->jobsProcessed = 0;
        $this->lastHeartbeatAt = null;
    }

    public function recordJobProcessed(): void
    {
        $this->jobsProcessed++;
    }

    public function jobsProcessed(): int
    {
        return $this->jobsProcessed;
    }

    public function shouldHeartbeat(): bool
    {
        if ($this->runId === null) {
            return false;
        }

        if ($this->lastHeartbeatAt === null) {
            return true;
        }

        return (microtime(true) - $this->lastHeartbeatAt) >= (int) config('queuewatch.workers.heartbeat_interval', 15);
    }

    public function bufferHeartbeat(int $memoryMb): void
    {
        if ($this->runId === null) {
            return;
        }

        try {
            $wrote = $this->withBufferLock(
                function () use ($memoryMb): bool {
                    $buffer = $this->store()->get(self::BUFFER_KEY, []);

                    $buffer[$this->runId] = [
                        'run_id' => $this->runId,
                        'last_seen' => now()->toIso8601String(),
                        'jobs_processed' => $this->jobsProcessed,
                        'memory_mb' => $memoryMb,
                    ];

                    $this->store()->put(self::BUFFER_KEY, $buffer, now()->addMinutes(10));

                    return true;
                },
                fn (): bool => false,
            );
        } catch (\Throwable $e) {
            Log::debug('Queuewatch worker heartbeat buffer write failed', ['error' => $e->getMessage()]);

            return;
        }

        if (! $wrote) {
            return;
        }

        $this->lastHeartbeatAt = microtime(true);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function takeBufferedHeartbeats(): array
    {
        try {
            return $this->withBufferLock(
                function (): array {
                    $buffer = $this->store()->get(self::BUFFER_KEY, []);

                    $this->store()->forget(self::BUFFER_KEY);

                    return array_values($buffer);
                },
                fn (): array => [],
            );
        } catch (\Throwable $e) {
            Log::debug('Queuewatch worker heartbeat buffer read failed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    public function isBackedOff(): bool
    {
        try {
            return (bool) $this->store()->get(self::BACKOFF_KEY, false);
        } catch (\Throwable $e) {
            Log::debug('Queuewatch worker backoff check failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    public function backOff(): void
    {
        try {
            $this->store()->put(self::BACKOFF_KEY, true, now()->addHour());
        } catch (\Throwable $e) {
            Log::debug('Queuewatch worker backoff write failed', ['error' => $e->getMessage()]);
        }
    }

    public function store(): Repository
    {
        return Cache::store(config('queuewatch.workers.cache_store'));
    }

    /**
     * Whether the configured cache store can survive between processes.
     *
     * The heartbeat buffer is written by the worker process and read by
     * the scheduled flush command, so a store scoped to a single process
     * (array, null) silently drops every heartbeat.
     */
    public function hasUsableStore(): bool
    {
        return ! in_array(
            config('cache.stores.'.$this->storeName().'.driver'),
            ['array', 'null'],
            true
        );
    }

    protected function storeName(): string
    {
        return config('queuewatch.workers.cache_store') ?? config('cache.default');
    }

    /**
     * Run $callback while holding a short, non-blocking lock on the
     * buffer key so concurrent worker processes on the same host cannot
     * lose each other's writes to a shared read-modify-write race.
     *
     * The lock is never awaited — a worker must not block on it. When it
     * cannot be acquired immediately, $onLockNotAcquired runs instead, so
     * bufferHeartbeat() simply skips that heartbeat (the worker tries
     * again next cycle) and takeBufferedHeartbeats() returns an empty
     * array without touching the buffer, so nothing already stored there
     * is lost.
     *
     * Not every cache store supports locking (e.g. APCu). When the
     * resolved store's driver doesn't implement LockProvider, $callback
     * runs unlocked so the package still works on those stores — this is
     * the buffer's original, pre-locking behaviour.
     */
    protected function withBufferLock(callable $callback, callable $onLockNotAcquired): mixed
    {
        $store = $this->store();

        if (! $store->getStore() instanceof LockProvider) {
            return $callback();
        }

        $lock = $store->lock(self::BUFFER_KEY.':lock', 5);

        if (! $lock->get()) {
            return $onLockNotAcquired();
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }
}
