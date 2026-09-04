<?php

use Illuminate\Contracts\Cache\Store;
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
})->skip(fn () => ! class_exists(WorkerStarting::class), 'Requires Laravel 12.20+');

it('backs off after hitting the worker limit', function () {
    Http::fake(['*' => Http::response(['success' => false], 429)]);

    app(TrackWorkerLifecycle::class)->handleStarting(
        new WorkerStarting('redis', 'default', new WorkerOptions)
    );

    expect(app(WorkerReporter::class)->isBackedOff())->toBeTrue();
})->skip(fn () => ! class_exists(WorkerStarting::class), 'Requires Laravel 12.20+');

it('sends nothing while backed off', function () {
    app(WorkerReporter::class)->backOff();
    Http::fake();

    app(TrackWorkerLifecycle::class)->handleStarting(
        new WorkerStarting('redis', 'default', new WorkerOptions)
    );

    Http::assertNothingSent();
})->skip(fn () => ! class_exists(WorkerStarting::class), 'Requires Laravel 12.20+');

it('does not back off on a successful start', function () {
    Http::fake(['*' => Http::response(['success' => true], 201)]);

    app(TrackWorkerLifecycle::class)->handleStarting(
        new WorkerStarting('redis', 'default', new WorkerOptions)
    );

    expect(app(WorkerReporter::class)->isBackedOff())->toBeFalse();
})->skip(fn () => ! class_exists(WorkerStarting::class), 'Requires Laravel 12.20+');

it('does not back off on a server error', function () {
    Http::fake(['*' => Http::response(['success' => false], 500)]);

    app(TrackWorkerLifecycle::class)->handleStarting(
        new WorkerStarting('redis', 'default', new WorkerOptions)
    );

    expect(app(WorkerReporter::class)->isBackedOff())->toBeFalse();
})->skip(fn () => ! class_exists(WorkerStarting::class), 'Requires Laravel 12.20+');

it('does not back off on a connection failure', function () {
    Http::fake(fn () => throw new Exception('connection refused'));

    app(TrackWorkerLifecycle::class)->handleStarting(
        new WorkerStarting('redis', 'default', new WorkerOptions)
    );

    expect(app(WorkerReporter::class)->isBackedOff())->toBeFalse();
})->skip(fn () => ! class_exists(WorkerStarting::class), 'Requires Laravel 12.20+');

it('does not let a cache store failure escape into the worker', function () {
    Cache::extend('queuewatch_throwing_test', function () {
        return Cache::repository(new class implements Store
        {
            public function get($key)
            {
                throw new RuntimeException('cache store unavailable');
            }

            public function many(array $keys)
            {
                throw new RuntimeException('cache store unavailable');
            }

            public function put($key, $value, $seconds)
            {
                throw new RuntimeException('cache store unavailable');
            }

            public function putMany(array $values, $seconds)
            {
                throw new RuntimeException('cache store unavailable');
            }

            public function increment($key, $value = 1)
            {
                throw new RuntimeException('cache store unavailable');
            }

            public function decrement($key, $value = 1)
            {
                throw new RuntimeException('cache store unavailable');
            }

            public function forever($key, $value)
            {
                throw new RuntimeException('cache store unavailable');
            }

            public function touch($key, $seconds)
            {
                throw new RuntimeException('cache store unavailable');
            }

            public function forget($key)
            {
                throw new RuntimeException('cache store unavailable');
            }

            public function flush()
            {
                throw new RuntimeException('cache store unavailable');
            }

            public function getPrefix()
            {
                return '';
            }
        });
    });

    config()->set('cache.stores.queuewatch_throwing_test', ['driver' => 'queuewatch_throwing_test']);
    config()->set('queuewatch.workers.cache_store', 'queuewatch_throwing_test');

    $reporter = app(WorkerReporter::class);

    expect($reporter->isBackedOff())->toBeFalse();

    $reporter->backOff();

    expect($reporter->isBackedOff())->toBeFalse();
});
