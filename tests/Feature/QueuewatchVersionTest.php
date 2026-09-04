<?php

use Queuewatch\Laravel\Queuewatch;

it('resolves a package version string', function () {
    expect(Queuewatch::version())->toBeString()->not->toBeEmpty();
});

it('defaults worker monitoring to off', function () {
    expect(config('queuewatch.workers.enabled'))->toBeFalse()
        ->and(config('queuewatch.workers.heartbeat_interval'))->toBe(15);
});
