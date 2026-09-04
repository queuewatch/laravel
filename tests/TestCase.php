<?php

namespace Queuewatch\Laravel\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Queuewatch\Laravel\QueuewatchServiceProvider;

class TestCase extends Orchestra
{
    /**
     * Worker monitoring config to apply before the application boots.
     *
     * QueuewatchServiceProvider::registerWorkerListeners() reads
     * queuewatch.workers.enabled and queuewatch.api_key while
     * QueuewatchServiceProvider::boot() runs, which happens before any
     * test body executes. A config()->set() call from inside a test is
     * too late to affect registration, so a test that needs the provider
     * to boot with a particular value sets these properties and calls
     * $this->refreshApplication() to rebuild the app through
     * getEnvironmentSetUp() again. Left null, they are a no-op and every
     * other test keeps the package's default config.
     */
    protected ?bool $workersEnabled = null;

    protected ?string $workersApiKey = null;

    protected function getPackageProviders($app)
    {
        return [
            QueuewatchServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app): void
    {
        config()->set('database.default', 'testing');

        if ($this->workersEnabled !== null) {
            config()->set('queuewatch.workers.enabled', $this->workersEnabled);
        }

        if ($this->workersApiKey !== null) {
            config()->set('queuewatch.api_key', $this->workersApiKey);
        }
    }
}
