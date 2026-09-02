<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Queuewatch\Laravel\Workers\WorkerReporter;

beforeEach(function () {
    Cache::flush();
    config()->set('queuewatch.api_key', 'test-key');
    config()->set('queuewatch.endpoint', 'https://api.queuewatch.test');
    config()->set('queuewatch.workers.enabled', true);
});

it('flushes the buffer as one batch request', function () {
    Http::fake();

    $reporter = app(WorkerReporter::class);
    $reporter->startRun('redis', 'default', null);
    $reporter->bufferHeartbeat(64);

    $this->artisan('queuewatch:workers:flush')->assertSuccessful();

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/v1/workers/heartbeats')
        && count($request['heartbeats']) === 1);

    expect($reporter->takeBufferedHeartbeats())->toBeEmpty();
});

it('sends nothing when the buffer is empty', function () {
    Http::fake();

    $this->artisan('queuewatch:workers:flush')->assertSuccessful();

    Http::assertNothingSent();
});

it('restores the buffer when the request fails so nothing is lost', function () {
    Http::fake(fn () => throw new Exception('connection refused'));

    $reporter = app(WorkerReporter::class);
    $reporter->startRun('redis', 'default', null);
    $reporter->bufferHeartbeat(64);

    $this->artisan('queuewatch:workers:flush')->assertSuccessful();

    expect($reporter->takeBufferedHeartbeats())->toHaveCount(1);
});

it('restores the buffer when the api rejects the request so nothing is lost', function () {
    Http::fake(['*' => Http::response(['error' => 'nope'], 500)]);

    $reporter = app(WorkerReporter::class);
    $reporter->startRun('redis', 'default', null);
    $reporter->bufferHeartbeat(64);

    $this->artisan('queuewatch:workers:flush')->assertSuccessful();

    expect($reporter->takeBufferedHeartbeats())->toHaveCount(1);
});

it('does nothing when worker monitoring is disabled', function () {
    Http::fake();
    config()->set('queuewatch.workers.enabled', false);

    $this->artisan('queuewatch:workers:flush')->assertSuccessful();

    Http::assertNothingSent();
});

it('chunks a large buffer into batches of 500', function () {
    Http::fake();

    $reporter = app(WorkerReporter::class);

    $buffer = collect(range(1, 1200))->mapWithKeys(fn ($i) => [
        "run-{$i}" => ['run_id' => (string) Str::uuid(), 'last_seen' => now()->toIso8601String(), 'jobs_processed' => 1, 'memory_mb' => 64],
    ])->all();

    $reporter->store()->put(WorkerReporter::BUFFER_KEY, $buffer, now()->addMinutes(10));

    $this->artisan('queuewatch:workers:flush')->assertSuccessful();

    Http::assertSentCount(3);
});
