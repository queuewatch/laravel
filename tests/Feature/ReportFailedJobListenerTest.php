<?php

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mvpopuk\LaravelEnhancedFailedJobs\Jobs\SendFailureReport;
use Mvpopuk\LaravelEnhancedFailedJobs\Listeners\ReportFailedJob;

beforeEach(function () {
    config()->set('queuewatch.api_key', 'test-api-key');
    config()->set('queuewatch.endpoint', 'https://api.queuewatch.io');
    config()->set('queuewatch.enabled', true);
    config()->set('queuewatch.project', 'Test Project');
    config()->set('queuewatch.environment', 'testing');
    config()->set('queuewatch.queue', 'sync');
    config()->set('queuewatch.ignored_jobs', []);
    config()->set('queuewatch.ignored_queues', []);
    config()->set('queuewatch.ignored_exceptions', []);
});

describe('ReportFailedJob Listener', function () {
    it('does not report SendFailureReport job failures to prevent infinite loops', function () {
        Http::fake();
        Queue::fake();

        $job = createMockJob(SendFailureReport::class);
        $event = new JobFailed('sync', $job, new Exception('Test exception'));

        $listener = new ReportFailedJob();
        $listener->handle($event);

        Queue::assertNothingPushed();
        Http::assertNothingSent();
    });

    it('reports regular job failures', function () {
        Http::fake([
            'api.queuewatch.io/*' => Http::response(['success' => true], 200),
        ]);

        $job = createMockJob('App\\Jobs\\TestJob');
        $event = new JobFailed('sync', $job, new Exception('Test exception'));

        $listener = new ReportFailedJob();
        $listener->handle($event);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/api/v1/failures');
        });
    });

    it('does not report when disabled', function () {
        config()->set('queuewatch.enabled', false);

        Http::fake();
        Queue::fake();

        $job = createMockJob('App\\Jobs\\TestJob');
        $event = new JobFailed('sync', $job, new Exception('Test exception'));

        $listener = new ReportFailedJob();
        $listener->handle($event);

        Queue::assertNothingPushed();
        Http::assertNothingSent();
    });

    it('does not report when api key is not set', function () {
        config()->set('queuewatch.api_key', null);

        Http::fake();
        Queue::fake();

        $job = createMockJob('App\\Jobs\\TestJob');
        $event = new JobFailed('sync', $job, new Exception('Test exception'));

        $listener = new ReportFailedJob();
        $listener->handle($event);

        Queue::assertNothingPushed();
        Http::assertNothingSent();
    });

    it('does not report ignored jobs', function () {
        config()->set('queuewatch.ignored_jobs', ['App\\Jobs\\IgnoredJob']);

        Http::fake();
        Queue::fake();

        $job = createMockJob('App\\Jobs\\IgnoredJob');
        $event = new JobFailed('sync', $job, new Exception('Test exception'));

        $listener = new ReportFailedJob();
        $listener->handle($event);

        Queue::assertNothingPushed();
        Http::assertNothingSent();
    });

    it('does not report ignored queues', function () {
        config()->set('queuewatch.ignored_queues', ['low-priority']);

        Http::fake();
        Queue::fake();

        $job = createMockJob('App\\Jobs\\TestJob', 'low-priority');
        $event = new JobFailed('sync', $job, new Exception('Test exception'));

        $listener = new ReportFailedJob();
        $listener->handle($event);

        Queue::assertNothingPushed();
        Http::assertNothingSent();
    });

    it('does not report ignored exceptions', function () {
        config()->set('queuewatch.ignored_exceptions', [RuntimeException::class]);

        Http::fake();
        Queue::fake();

        $job = createMockJob('App\\Jobs\\TestJob');
        $event = new JobFailed('sync', $job, new RuntimeException('Test exception'));

        $listener = new ReportFailedJob();
        $listener->handle($event);

        Queue::assertNothingPushed();
        Http::assertNothingSent();
    });
});

/**
 * Create a mock job for testing.
 */
function createMockJob(string $jobClass, string $queue = 'default'): object
{
    $payload = [
        'uuid' => 'test-uuid-123',
        'displayName' => $jobClass,
        'job' => $jobClass,
        'maxTries' => 3,
        'timeout' => null,
        'data' => ['command' => serialize(new stdClass())],
    ];

    $mock = Mockery::mock('Illuminate\Contracts\Queue\Job');
    $mock->shouldReceive('getJobId')->andReturn('1');
    $mock->shouldReceive('uuid')->andReturn('test-uuid-123');
    $mock->shouldReceive('resolveName')->andReturn($jobClass);
    $mock->shouldReceive('getQueue')->andReturn($queue);
    $mock->shouldReceive('attempts')->andReturn(1);
    $mock->shouldReceive('maxTries')->andReturn(3);
    $mock->shouldReceive('timeout')->andReturn(null);
    $mock->shouldReceive('payload')->andReturn($payload);

    return $mock;
}
