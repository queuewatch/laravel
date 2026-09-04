<?php

use Illuminate\Contracts\Cache\Store;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStarting;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Queuewatch\Laravel\Listeners\TrackWorkerLifecycle;
use Queuewatch\Laravel\Workers\WorkerReporter;

beforeEach(function () {
    if (! class_exists(WorkerStarting::class)) {
        $this->markTestSkipped('Requires Laravel 12.20+');
    }

    // A store that counts get() calls, so a backoff set mid-run can prove
    // it is only consulted once per loop-suppression rather than once per
    // loop iteration (see TrackWorkerLifecycle::handleLooping()).
    Cache::extend('queuewatch_counting_test', function () {
        return Cache::repository(new class implements Store
        {
            public int $gets = 0;

            protected array $items = [];

            public function get($key)
            {
                $this->gets++;

                return $this->items[$key] ?? null;
            }

            public function many(array $keys)
            {
                return array_combine($keys, array_map(fn ($key) => $this->get($key), $keys));
            }

            public function put($key, $value, $seconds)
            {
                $this->items[$key] = $value;

                return true;
            }

            public function putMany(array $values, $seconds)
            {
                foreach ($values as $key => $value) {
                    $this->put($key, $value, $seconds);
                }

                return true;
            }

            public function increment($key, $value = 1)
            {
                return $this->items[$key] = ($this->items[$key] ?? 0) + $value;
            }

            public function decrement($key, $value = 1)
            {
                return $this->increment($key, -$value);
            }

            public function forever($key, $value)
            {
                return $this->put($key, $value, 0);
            }

            public function touch($key, $seconds)
            {
                return true;
            }

            public function forget($key)
            {
                unset($this->items[$key]);

                return true;
            }

            public function flush()
            {
                $this->items = [];

                return true;
            }

            public function getPrefix()
            {
                return '';
            }
        });
    });

    config()->set('cache.stores.queuewatch_counting_test', ['driver' => 'queuewatch_counting_test']);
    config()->set('queuewatch.workers.cache_store', 'queuewatch_counting_test');
    config()->set('queuewatch.api_key', 'test-key');
    config()->set('queuewatch.workers.enabled', true);
    config()->set('queuewatch.workers.heartbeat_interval', 15);

    Http::fake();

    $this->listener = app(TrackWorkerLifecycle::class);
    $this->reporter = app(WorkerReporter::class);
    $this->listener->handleStarting(new WorkerStarting('redis', 'default', new WorkerOptions));
});

it('does not read the backoff cache key on every loop while suppressed by a mid-run backoff', function () {
    $this->reporter->backOff();

    $store = Cache::store('queuewatch_counting_test')->getStore();
    $getsBefore = $store->gets;

    for ($i = 0; $i < 50; $i++) {
        $this->listener->handleLooping(new Looping('redis', 'default'));
    }

    // The first suppressed loop pays for the read that discovers the
    // backoff and defers the throttle; every loop after that is stopped
    // by shouldHeartbeat() before enabled() -- and therefore the cache --
    // is consulted again.
    expect($store->gets - $getsBefore)->toBe(1);
});
