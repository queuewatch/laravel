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

    /**
     * Flush buffered heartbeats, honouring the same backoff contract as
     * every other outbound request this package makes.
     *
     * Returns early when already backed off, rather than draining the
     * buffer only to restore it again — a revoked api key would
     * otherwise return 403 forever while this still ran every minute,
     * refreshing the buffer TTL each time. On a fresh 403/429 the chunk
     * is dropped, not restored: restoring it would just recreate the
     * same unbounded retry. Restoring is reserved for failures that are
     * plausibly transient — a 5xx or a transport exception.
     */
    public function handle(WorkerReporter $reporter, QueuewatchClient $client): int
    {
        if (! config('queuewatch.workers.enabled', false) || empty(config('queuewatch.api_key'))) {
            return self::SUCCESS;
        }

        if ($reporter->isBackedOff()) {
            return self::SUCCESS;
        }

        $heartbeats = $reporter->takeBufferedHeartbeats();

        if ($heartbeats === []) {
            return self::SUCCESS;
        }

        foreach (array_chunk($heartbeats, 500) as $chunk) {
            try {
                $response = $client->flushWorkerHeartbeats($chunk);

                if (in_array($response->status(), [403, 429], true)) {
                    Log::debug('Queuewatch heartbeat flush rejected', ['status' => $response->status()]);

                    $reporter->backOff();

                    continue;
                }

                if ($response->serverError()) {
                    Log::debug('Queuewatch heartbeat flush rejected', ['status' => $response->status()]);

                    $reporter->restoreHeartbeats($chunk);
                } elseif ($response->failed()) {
                    Log::debug('Queuewatch heartbeat flush rejected', ['status' => $response->status()]);
                }
            } catch (Throwable $e) {
                Log::debug('Queuewatch heartbeat flush failed', ['error' => $e->getMessage()]);

                $reporter->restoreHeartbeats($chunk);
            }
        }

        return self::SUCCESS;
    }
}
