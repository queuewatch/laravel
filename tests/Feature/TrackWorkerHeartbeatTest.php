<?php

use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStarting;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Http;
use Queuewatch\Laravel\Listeners\TrackWorkerLifecycle;
use Queuewatch\Laravel\Workers\WorkerReporter;

beforeEach(function () {
    Http::fake();
    config()->set('queuewatch.api_key', 'test-key');
    config()->set('queuewatch.workers.enabled', true);
    config()->set('queuewatch.workers.heartbeat_interval', 15);

    $this->listener = app(TrackWorkerLifecycle::class);
    $this->reporter = app(WorkerReporter::class);
    $this->listener->handleStarting(new WorkerStarting('redis', 'default', new WorkerOptions));
    Http::fake();
});

it('buffers a heartbeat without making a request', function () {
    $this->listener->handleLooping(new Looping('redis', 'default'));

    expect($this->reporter->takeBufferedHeartbeats())->toHaveCount(1);
    Http::assertNothingSent();
});

it('respects the heartbeat interval across loops', function () {
    $this->listener->handleLooping(new Looping('redis', 'default'));
    $this->listener->handleJobProcessed(new stdClass);
    $this->listener->handleLooping(new Looping('redis', 'default'));
    $this->listener->handleJobProcessed(new stdClass);
    $this->listener->handleLooping(new Looping('redis', 'default'));

    $heartbeats = $this->reporter->takeBufferedHeartbeats();

    expect($heartbeats)->toHaveCount(1)
        ->and($heartbeats[0]['jobs_processed'])->toBe(0);
});

it('counts processed jobs into the heartbeat', function () {
    $this->listener->handleJobProcessed(new stdClass);
    $this->listener->handleJobProcessed(new stdClass);
    $this->listener->handleLooping(new Looping('redis', 'default'));

    expect($this->reporter->takeBufferedHeartbeats()[0]['jobs_processed'])->toBe(2);
});

it('buffers nothing when no run was registered', function () {
    $this->reporter->clearRun();

    $this->listener->handleLooping(new Looping('redis', 'default'));

    expect($this->reporter->takeBufferedHeartbeats())->toBeEmpty();
});
