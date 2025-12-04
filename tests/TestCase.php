<?php

namespace Queuewatch\Laravel\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Queuewatch\Laravel\QueuewatchServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            QueuewatchServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');
    }
}
