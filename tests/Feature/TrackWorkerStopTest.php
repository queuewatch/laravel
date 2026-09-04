<?php

use Illuminate\Queue\Events\WorkerStarting;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Queue\WorkerStopReason;
use Illuminate\Support\Facades\Http;
use Queuewatch\Laravel\Listeners\TrackWorkerLifecycle;
use Queuewatch\Laravel\Workers\WorkerReporter;

beforeEach(function () {
    if (! class_exists(WorkerStarting::class)) {
        $this->markTestSkipped('Requires Laravel 12.20+');
    }

    Http::fake();
    config()->set('queuewatch.api_key', 'test-key');
    config()->set('queuewatch.endpoint', 'https://api.queuewatch.test');
    config()->set('queuewatch.workers.enabled', true);

    $this->listener = app(TrackWorkerLifecycle::class);
    $this->reporter = app(WorkerReporter::class);
    $this->listener->handleStarting(new WorkerStarting('redis', 'default', new WorkerOptions));
    $this->runId = $this->reporter->runId();
    Http::fake();
});

it('reports the structured stop reason', function () {
    $this->listener->handleStopping(new WorkerStopping(
        status: 12,
        workerOptions: new WorkerOptions,
        reason: WorkerStopReason::MaxMemoryExceeded,
        jobsProcessed: 40,
        lastJobProcessedAt: null,
        memoryUsage: 131,
        connectionName: 'redis',
        queue: 'default',
    ));

    Http::assertSent(function ($request) {
        return str_ends_with($request->url(), "/api/v1/workers/runs/{$this->runId}/stop")
            && $request['reason'] === 'memory'
            && $request['status'] === 12
            && $request['jobs_processed'] === 40;
    });
})->skip(fn () => ! property_exists(WorkerStopping::class, 'jobsProcessed'), 'Requires Laravel 13.30+');

it('reports the stop even when the reason enum has no description() method', function () {
    // Regression test for a Laravel 12.20-13.29 crash: WorkerStopReason
    // was backported to 12.x with every case but without description(),
    // so calling it there threw and silently dropped every stop report.
    // This enum reproduces that shape without depending on which
    // framework version is actually installed.
    $event = new WorkerStopping(12);
    $event->reason = StopReasonWithoutDescription::Custom;

    $this->listener->handleStopping($event);

    Http::assertSent(function ($request) {
        return str_ends_with($request->url(), "/api/v1/workers/runs/{$this->runId}/stop")
            && $request['reason'] === 'custom'
            && $request['status'] === 12;
    });
});

it('degrades gracefully when the framework reports no reason', function () {
    $this->listener->handleStopping(new WorkerStopping(0));

    Http::assertSent(function ($request) {
        return $request['reason'] === null && $request['status'] === 0;
    });
});

it('falls back to its own job count when the event carries none', function () {
    $this->listener->handleJobProcessed(new stdClass);
    $this->listener->handleJobProcessed(new stdClass);

    $this->listener->handleStopping(new WorkerStopping(0));

    Http::assertSent(fn ($request) => $request['jobs_processed'] === 2);
});

it('sends nothing when no run was registered', function () {
    $this->reporter->clearRun();

    $this->listener->handleStopping(new WorkerStopping(0));

    Http::assertNothingSent();
});

it('clears the run after stopping', function () {
    $this->listener->handleStopping(new WorkerStopping(0));

    expect($this->reporter->runId())->toBeNull();
});

/**
 * Stands in for Illuminate\Queue\WorkerStopReason as it shipped on
 * Laravel 12.20-13.29: a backed enum with cases (so ->value works) but
 * no description() method.
 */
enum StopReasonWithoutDescription: string
{
    case Custom = 'custom';
}
