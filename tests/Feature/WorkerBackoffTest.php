<?php

use Illuminate\Queue\Events\WorkerStarting;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Queuewatch\Laravel\Listeners\TrackWorkerLifecycle;
use Queuewatch\Laravel\Workers\WorkerReporter;

beforeEach(function () {
    Cache::flush();
    config()->set('queuewatch.api_key', 'test-key');
    config()->set('queuewatch.endpoint', 'https://api.queuewatch.test');
    config()->set('queuewatch.workers.enabled', true);
});

it('backs off after a plan rejection', function () {
    Http::fake(['*' => Http::response(['success' => false], 403)]);

    app(TrackWorkerLifecycle::class)->handleStarting(
        new WorkerStarting('redis', 'default', new WorkerOptions)
    );

    expect(app(WorkerReporter::class)->isBackedOff())->toBeTrue();
});

it('backs off after hitting the worker limit', function () {
    Http::fake(['*' => Http::response(['success' => false], 429)]);

    app(TrackWorkerLifecycle::class)->handleStarting(
        new WorkerStarting('redis', 'default', new WorkerOptions)
    );

    expect(app(WorkerReporter::class)->isBackedOff())->toBeTrue();
});

it('sends nothing while backed off', function () {
    app(WorkerReporter::class)->backOff();
    Http::fake();

    app(TrackWorkerLifecycle::class)->handleStarting(
        new WorkerStarting('redis', 'default', new WorkerOptions)
    );

    Http::assertNothingSent();
});

it('does not back off on a successful start', function () {
    Http::fake(['*' => Http::response(['success' => true], 201)]);

    app(TrackWorkerLifecycle::class)->handleStarting(
        new WorkerStarting('redis', 'default', new WorkerOptions)
    );

    expect(app(WorkerReporter::class)->isBackedOff())->toBeFalse();
});
