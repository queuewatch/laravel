<?php

use Illuminate\Support\Facades\Cache;
use Queuewatch\Laravel\Workers\WorkerReporter;

beforeEach(function () {
    Cache::flush();
    $this->reporter = new WorkerReporter;
});

it('mints a run id on start', function () {
    expect($this->reporter->runId())->toBeNull();

    $this->reporter->startRun('redis', 'default', null);

    expect($this->reporter->runId())->toBeString()->toHaveLength(36);
});

it('counts processed jobs', function () {
    $this->reporter->startRun('redis', 'default', null);
    $this->reporter->recordJobProcessed();
    $this->reporter->recordJobProcessed();

    expect($this->reporter->jobsProcessed())->toBe(2);
});

it('throttles heartbeats to the configured interval', function () {
    config()->set('queuewatch.workers.heartbeat_interval', 15);
    $this->reporter->startRun('redis', 'default', null);

    expect($this->reporter->shouldHeartbeat())->toBeTrue();

    $this->reporter->bufferHeartbeat(64);

    expect($this->reporter->shouldHeartbeat())->toBeFalse();
});

it('buffers and drains heartbeats', function () {
    $this->reporter->startRun('redis', 'default', null);
    $this->reporter->bufferHeartbeat(96);

    $drained = $this->reporter->takeBufferedHeartbeats();

    expect($drained)->toHaveCount(1)
        ->and($drained[0]['run_id'])->toBe($this->reporter->runId())
        ->and($drained[0]['memory_mb'])->toBe(96)
        ->and($this->reporter->takeBufferedHeartbeats())->toBeEmpty();
});

it('keeps one buffer entry per run', function () {
    $this->reporter->startRun('redis', 'default', null);
    $this->reporter->bufferHeartbeat(64);
    $this->reporter->recordJobProcessed();
    $this->reporter->bufferHeartbeat(80);

    $drained = $this->reporter->takeBufferedHeartbeats();

    expect($drained)->toHaveCount(1)
        ->and($drained[0]['memory_mb'])->toBe(80)
        ->and($drained[0]['jobs_processed'])->toBe(1);
});
