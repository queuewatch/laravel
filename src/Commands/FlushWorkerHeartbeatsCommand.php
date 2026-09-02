<?php

namespace Queuewatch\Laravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Queuewatch\Laravel\Api\QueuewatchClient;
use Queuewatch\Laravel\Workers\WorkerReporter;
use Throwable;

class FlushWorkerHeartbeatsCommand extends Command
{
    protected $signature = 'queuewatch:workers:flush';

    protected $description = 'Flush buffered worker heartbeats to Queuewatch';

    public function handle(WorkerReporter $reporter, QueuewatchClient $client): int
    {
        if (! config('queuewatch.workers.enabled', false) || empty(config('queuewatch.api_key'))) {
            return self::SUCCESS;
        }

        $heartbeats = $reporter->takeBufferedHeartbeats();

        if ($heartbeats === []) {
            return self::SUCCESS;
        }

        foreach (array_chunk($heartbeats, 500) as $chunk) {
            try {
                $client->flushWorkerHeartbeats($chunk);
            } catch (Throwable $e) {
                Log::debug('Queuewatch heartbeat flush failed', ['error' => $e->getMessage()]);

                $this->restore($reporter, $chunk);
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array<string, mixed>>  $heartbeats
     */
    protected function restore(WorkerReporter $reporter, array $heartbeats): void
    {
        $buffer = $reporter->store()->get(WorkerReporter::BUFFER_KEY, []);

        foreach ($heartbeats as $heartbeat) {
            $buffer[$heartbeat['run_id']] ??= $heartbeat;
        }

        $reporter->store()->put(WorkerReporter::BUFFER_KEY, $buffer, now()->addMinutes(10));
    }
}
