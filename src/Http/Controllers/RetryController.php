<?php

namespace Mvpopuk\LaravelEnhancedFailedJobs\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

class RetryController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        // Verify the request signature
        if (! $this->verifySignature($request)) {
            Log::warning('QueueWatch retry request failed signature verification');

            return response()->json([
                'success' => false,
                'error' => 'Invalid signature',
            ], 401);
        }

        // Validate required fields
        $validated = $request->validate([
            'action' => ['required', 'string', 'in:retry'],
            'job_uuid' => ['nullable', 'string'],
            'job_id' => ['nullable', 'string'],
            'job_class' => ['required', 'string'],
            'connection' => ['nullable', 'string'],
            'queue' => ['nullable', 'string'],
            'payload' => ['required', 'array'],
        ]);

        // Check if queue is allowed
        $queue = $validated['queue'] ?? 'default';
        if (! $this->isQueueAllowed($queue)) {
            Log::warning('QueueWatch retry request for disallowed queue', ['queue' => $queue]);

            return response()->json([
                'success' => false,
                'error' => 'Queue not allowed for retry',
            ], 403);
        }

        try {
            $this->dispatchJob($validated);

            Log::info('QueueWatch job retry successful', [
                'job_class' => $validated['job_class'],
                'queue' => $queue,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Job dispatched successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('QueueWatch job retry failed', [
                'job_class' => $validated['job_class'],
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to dispatch job: '.$e->getMessage(),
            ], 500);
        }
    }

    protected function verifySignature(Request $request): bool
    {
        $signature = $request->header('X-Queuewatch-Signature');

        if (empty($signature)) {
            return false;
        }

        $apiKey = config('queuewatch.api_key');

        if (empty($apiKey)) {
            return false;
        }

        $payload = $request->all();
        $expectedSignature = hash_hmac('sha256', json_encode($payload), $apiKey);

        return hash_equals($expectedSignature, $signature);
    }

    protected function isQueueAllowed(string $queue): bool
    {
        $allowedQueues = config('queuewatch.retry.allowed_queues', ['*']);

        if (in_array('*', $allowedQueues)) {
            return true;
        }

        return in_array($queue, $allowedQueues);
    }

    protected function dispatchJob(array $data): void
    {
        $jobClass = $data['job_class'];
        $payload = $data['payload'];
        $connection = $data['connection'] ?? config('queue.default');
        $queue = $data['queue'] ?? 'default';
        $delay = config('queuewatch.retry.delay', 0);

        // The payload contains the serialized job data
        // We need to reconstruct and dispatch it
        if (isset($payload['data']['command'])) {
            // Laravel's standard queue payload format
            $command = unserialize($payload['data']['command']);

            $job = dispatch($command)
                ->onConnection($connection)
                ->onQueue($queue);

            if ($delay > 0) {
                $job->delay($delay);
            }
        } elseif (class_exists($jobClass)) {
            // If we can't unserialize, try to create a new instance
            // This is a fallback and may not work for jobs with constructor dependencies
            throw new \Exception('Unable to reconstruct job from payload. The job command data is missing.');
        } else {
            throw new \Exception("Job class {$jobClass} does not exist.");
        }
    }
}
