<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStarting;
use Illuminate\Support\Facades\Event;
use Queuewatch\Laravel\Workers\WorkerReporter;

it('registers the reporter as a singleton', function () {
    expect(app(WorkerReporter::class))->toBe(app(WorkerReporter::class));
});

it('registers no worker listeners when disabled', function () {
    // Testbench boots the provider before the test body runs, so a plain
    // config()->set() here would come too late to affect registration.
    // Setting the property and rebuilding the application re-runs
    // TestCase::getEnvironmentSetUp() before QueuewatchServiceProvider::boot()
    // executes again, so the disabled config is actually in place at boot.
    $this->workersEnabled = false;
    $this->refreshApplication();

    expect(Event::hasListeners(WorkerStarting::class))->toBeFalse()
        ->and(Event::hasListeners(Looping::class))->toBeFalse();
});

it('warns when the configured cache store cannot survive between processes', function () {
    config()->set('queuewatch.workers.enabled', true);
    config()->set('queuewatch.api_key', 'test-key');
    config()->set('queuewatch.workers.cache_store', 'array');

    expect(app(WorkerReporter::class)->hasUsableStore())->toBeFalse();
});

it('schedules the flush command every minute', function () {
    // Same boot-timing issue as the disabled test above, but in reverse:
    // the provider needs to see worker monitoring enabled while it boots
    // for the schedule entry to be registered.
    $this->workersEnabled = true;
    $this->workersApiKey = 'test-key';
    $this->refreshApplication();

    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains($event->command ?? '', 'queuewatch:workers:flush'));

    expect($events)->not->toBeEmpty();
});
