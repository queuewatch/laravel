<?php

namespace Queuewatch\Laravel\Api;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Queuewatch\Laravel\Queuewatch;

class QueuewatchClient
{
    protected string $apiKey;

    protected string $endpoint;

    protected int $timeout;

    public function __construct(?string $apiKey = null, ?string $endpoint = null, ?int $timeout = null)
    {
        $this->apiKey = $apiKey ?? config('queuewatch.api_key') ?? '';
        $this->endpoint = rtrim($endpoint ?? config('queuewatch.endpoint') ?? 'https://api.queuewatch.io', '/');
        $this->timeout = $timeout ?? config('queuewatch.timeout') ?? 5;
    }

    public function isConfigured(): bool
    {
        return ! empty($this->apiKey);
    }

    public function reportFailure(array $payload): Response
    {
        return $this->request()->post('/api/v1/failures', $payload);
    }

    public function testConnection(): Response
    {
        return $this->request()->get('/api/v1/ping');
    }

    public function getProject(): Response
    {
        return $this->request()->get('/api/v1/project');
    }

    public function startWorkerRun(array $payload): Response
    {
        return $this->request()->post('/api/v1/workers/runs', $payload);
    }

    public function stopWorkerRun(string $runId, array $payload): Response
    {
        return $this->request()->post("/api/v1/workers/runs/{$runId}/stop", $payload);
    }

    protected function request(): PendingRequest
    {
        return Http::baseUrl($this->endpoint)
            ->timeout($this->timeout)
            ->withHeaders([
                'Authorization' => 'Bearer '.$this->apiKey,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'X-Queuewatch-Agent' => 'queuewatch/laravel '.Queuewatch::version(),
            ]);
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }
}
