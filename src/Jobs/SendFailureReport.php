<?php

namespace Queuewatch\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Queuewatch\Laravel\Api\QueuewatchClient;

class SendFailureReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 10;

    /**
     * Delete the job if its models no longer exist.
     */
    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public array $payload
    ) {}

    public function handle(QueuewatchClient $client): void
    {
        if (! $client->isConfigured()) {
            return;
        }

        try {
            $response = $client->reportFailure($this->payload);

            if ($response->failed()) {
                Log::warning('Queuewatch: Failed to report job failure', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                // Retry on server errors
                if ($response->serverError()) {
                    $this->release($this->backoff);
                }
            }
        } catch (\Throwable $e) {
            Log::error('Queuewatch: Exception while reporting job failure', [
                'message' => $e->getMessage(),
            ]);

            // Don't let reporting failures cause more failures
            // Just log and move on after max retries
            if ($this->attempts() >= $this->tries) {
                return;
            }

            $this->release($this->backoff);
        }
    }

    /**
     * Determine if the job should be marked as failed on timeout.
     */
    public function shouldMarkAsFailedOnTimeout(): bool
    {
        return false;
    }

    /**
     * Handle a job failure.
     */
    public function failed(?\Throwable $exception): void
    {
        // Don't report failures of the failure reporter to avoid infinite loops
        Log::warning('Queuewatch: Failed to send failure report after all retries', [
            'exception' => $exception?->getMessage(),
        ]);
    }
}
