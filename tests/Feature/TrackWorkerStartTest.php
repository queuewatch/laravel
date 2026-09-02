<?php

use Illuminate\Queue\Events\WorkerStarting;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Http;
use Queuewatch\Laravel\Listeners\TrackWorkerLifecycle;
use Queuewatch\Laravel\Workers\WorkerReporter;

beforeEach(function () {
    Http::fake();
    config()->set('queuewatch.api_key', 'test-key');
    config()->set('queuewatch.endpoint', 'https://api.queuewatch.test');
    config()->set('queuewatch.workers.enabled', true);
    config()->set('queuewatch.workers.heartbeat_interval', 15);
});

it('registers a run when the worker starts', function () {
    $reporter = app(WorkerReporter::class);
    $listener = app(TrackWorkerLifecycle::class);

    $options = new WorkerOptions;
    $options->name = 'emails';

    $listener->handleStarting(new WorkerStarting('redis', 'emails,default', $options));

    expect($reporter->runId())->not->toBeNull();

    Http::assertSent(function ($request) use ($reporter) {
        return str_ends_with($request->url(), '/api/v1/workers/runs')
            && $request['run_id'] === $reporter->runId()
            && $request['hostname'] === gethostname()
            && $request['queues'] === ['emails', 'default']
            && $request['name'] === 'emails'
            && $request['heartbeat_interval'] === 15
            && $request['php_version'] === PHP_VERSION;
    });
});

it('does nothing when worker monitoring is disabled', function () {
    config()->set('queuewatch.workers.enabled', false);

    app(TrackWorkerLifecycle::class)->handleStarting(
        new WorkerStarting('redis', 'default', new WorkerOptions)
    );

    Http::assertNothingSent();
});

it('does nothing without an api key', function () {
    config()->set('queuewatch.api_key', null);

    app(TrackWorkerLifecycle::class)->handleStarting(
        new WorkerStarting('redis', 'default', new WorkerOptions)
    );

    Http::assertNothingSent();
});

it('swallows a transport failure', function () {
    Http::fake(fn () => throw new Exception('connection refused'));

    app(TrackWorkerLifecycle::class)->handleStarting(
        new WorkerStarting('redis', 'default', new WorkerOptions)
    );
})->throwsNoExceptions();
