<?php

namespace Queuewatch\Laravel\Workers;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class WorkerReporter
{
    public const BUFFER_KEY = 'queuewatch:worker-heartbeats';

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

        $buffer = $this->store()->get(self::BUFFER_KEY, []);

        $buffer[$this->runId] = [
            'run_id' => $this->runId,
            'last_seen' => now()->toIso8601String(),
            'jobs_processed' => $this->jobsProcessed,
            'memory_mb' => $memoryMb,
        ];

        $this->store()->put(self::BUFFER_KEY, $buffer, now()->addMinutes(10));

        $this->lastHeartbeatAt = microtime(true);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function takeBufferedHeartbeats(): array
    {
        $buffer = $this->store()->get(self::BUFFER_KEY, []);

        $this->store()->forget(self::BUFFER_KEY);

        return array_values($buffer);
    }

    public function store(): Repository
    {
        return Cache::store(config('queuewatch.workers.cache_store'));
    }
}
