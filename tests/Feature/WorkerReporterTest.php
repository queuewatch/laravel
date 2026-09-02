<?php

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Store;
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

it('buffers a heartbeat normally when the cache store supports locking', function () {
    $store = Cache::store(config('queuewatch.workers.cache_store'));
    expect($store->getStore())->toBeInstanceOf(LockProvider::class);

    $this->reporter->startRun('redis', 'default', null);
    $this->reporter->bufferHeartbeat(50);

    $drained = $this->reporter->takeBufferedHeartbeats();

    expect($drained)->toHaveCount(1)
        ->and($drained[0]['memory_mb'])->toBe(50);
});

it('still buffers a heartbeat when the cache store does not support locking', function () {
    Cache::extend('queuewatch_no_lock_test', function () {
        return Cache::repository(new class implements Store
        {
            protected array $items = [];

            public function get($key)
            {
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

    config()->set('cache.stores.queuewatch_no_lock_test', ['driver' => 'queuewatch_no_lock_test']);
    config()->set('queuewatch.workers.cache_store', 'queuewatch_no_lock_test');

    $store = Cache::store('queuewatch_no_lock_test');
    expect($store->getStore())->not->toBeInstanceOf(LockProvider::class);

    $this->reporter->startRun('redis', 'default', null);
    $this->reporter->bufferHeartbeat(72);

    $drained = $this->reporter->takeBufferedHeartbeats();

    expect($drained)->toHaveCount(1)
        ->and($drained[0]['memory_mb'])->toBe(72)
        ->and($this->reporter->takeBufferedHeartbeats())->toBeEmpty();
});

it('skips buffering a heartbeat when another process already holds the buffer lock', function () {
    $store = Cache::store(config('queuewatch.workers.cache_store'));
    $externalLock = $store->lock(WorkerReporter::BUFFER_KEY.':lock', 5);

    expect($externalLock->get())->toBeTrue();

    $this->reporter->startRun('redis', 'default', null);
    $this->reporter->bufferHeartbeat(64);

    $externalLock->release();

    expect($store->get(WorkerReporter::BUFFER_KEY, []))->toBeEmpty();
});
