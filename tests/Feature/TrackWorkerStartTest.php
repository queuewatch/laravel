<?php

use Illuminate\Queue\Events\WorkerStarting;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Http;
use Queuewatch\Laravel\Listeners\TrackWorkerLifecycle;
use Queuewatch\Laravel\Workers\WorkerReporter;

beforeEach(function () {
    config()->set('queuewatch.api_key', 'test-key');
    config()->set('queuewatch.endpoint', 'https://api.queuewatch.test');
    config()->set('queuewatch.workers.enabled', true);
    config()->set('queuewatch.workers.heartbeat_interval', 15);
});

it('registers a run when the worker starts', function () {
    Http::fake();

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
})->skip(fn () => ! class_exists(WorkerStarting::class), 'Requires Laravel 12.20+');

it('does nothing when worker monitoring is disabled', function () {
    Http::fake();
    config()->set('queuewatch.workers.enabled', false);

    app(TrackWorkerLifecycle::class)->handleStarting(
        new WorkerStarting('redis', 'default', new WorkerOptions)
    );

    Http::assertNothingSent();
})->skip(fn () => ! class_exists(WorkerStarting::class), 'Requires Laravel 12.20+');

it('does nothing without an api key', function () {
    Http::fake();
    config()->set('queuewatch.api_key', null);

    app(TrackWorkerLifecycle::class)->handleStarting(
        new WorkerStarting('redis', 'default', new WorkerOptions)
    );

    Http::assertNothingSent();
})->skip(fn () => ! class_exists(WorkerStarting::class), 'Requires Laravel 12.20+');

it('swallows a transport failure', function () {
    Http::fake(fn () => throw new Exception('connection refused'));

    app(TrackWorkerLifecycle::class)->handleStarting(
        new WorkerStarting('redis', 'default', new WorkerOptions)
    );
})->throwsNoExceptions()->skip(fn () => ! class_exists(WorkerStarting::class), 'Requires Laravel 12.20+');

it('clears the run when the registration request throws', function () {
    Http::fake(fn () => throw new Exception('connection refused'));

    $reporter = app(WorkerReporter::class);

    app(TrackWorkerLifecycle::class)->handleStarting(
        new WorkerStarting('redis', 'default', new WorkerOptions)
    );

    expect($reporter->runId())->toBeNull();
})->skip(fn () => ! class_exists(WorkerStarting::class), 'Requires Laravel 12.20+');

it('clears the run when the registration request returns a server error', function () {
    Http::fake(['*' => Http::response(['error' => 'nope'], 500)]);

    $reporter = app(WorkerReporter::class);

    app(TrackWorkerLifecycle::class)->handleStarting(
        new WorkerStarting('redis', 'default', new WorkerOptions)
    );

    expect($reporter->runId())->toBeNull();
})->skip(fn () => ! class_exists(WorkerStarting::class), 'Requires Laravel 12.20+');

it('reports which kind of worker process this is', function (?string $command, string $expected) {
    Http::fake();

    $original = $_SERVER['argv'] ?? null;
    $_SERVER['argv'] = $command === null ? ['artisan'] : ['artisan', $command];

    try {
        app(TrackWorkerLifecycle::class)->handleStarting(
            new WorkerStarting('redis', 'default', new WorkerOptions)
        );

        Http::assertSent(fn ($request) => $request['worker_type'] === $expected);
    } finally {
        if ($original === null) {
            unset($_SERVER['argv']);
        } else {
            $_SERVER['argv'] = $original;
        }
    }
})->with([
    'horizon' => ['horizon:work', 'horizon'],
    'queue:work' => ['queue:work', 'queue:work'],
    'queue:listen' => ['queue:listen', 'queue:listen'],
    'something else' => ['some:command', 'other'],
    'no argv at all' => [null, 'other'],
])->skip(fn () => ! class_exists(WorkerStarting::class), 'Requires Laravel 12.20+');
