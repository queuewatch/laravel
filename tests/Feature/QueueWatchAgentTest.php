<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Queuewatch\Laravel\Api\QueuewatchClient;
use Queuewatch\Laravel\Jobs\SendFailureReport;

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

describe('QueuewatchClient', function () {
    it('is configured when api key is set', function () {
        $client = new QueuewatchClient('test-key');

        expect($client->isConfigured())->toBeTrue();
    });

    it('is not configured when api key is empty', function () {
        $client = new QueuewatchClient('');

        expect($client->isConfigured())->toBeFalse();
    });

    it('sends failure report to api', function () {
        Http::fake([
            'api.queuewatch.io/*' => Http::response(['success' => true], 200),
        ]);

        $client = new QueuewatchClient('test-key', 'https://api.queuewatch.io');

        $response = $client->reportFailure([
            'project' => 'Test',
            'job' => ['name' => 'TestJob'],
        ]);

        expect($response->successful())->toBeTrue();

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://api.queuewatch.io/api/v1/failures'
                && $request->hasHeader('Authorization', 'Bearer test-key');
        });
    });

    it('tests connection to api', function () {
        Http::fake([
            'api.queuewatch.io/*' => Http::response(['message' => 'pong'], 200),
        ]);

        $client = new QueuewatchClient('test-key', 'https://api.queuewatch.io');

        $response = $client->testConnection();

        expect($response->successful())->toBeTrue();
        expect($response->json('message'))->toBe('pong');
    });
});

describe('SendFailureReport Job', function () {
    it('sends payload to api', function () {
        Http::fake([
            'api.queuewatch.io/*' => Http::response(['success' => true], 200),
        ]);

        $payload = [
            'project' => 'Test',
            'job' => ['name' => 'TestJob'],
            'exception' => ['message' => 'Test error'],
        ];

        $job = new SendFailureReport($payload);
        $job->handle(new QueuewatchClient('test-key', 'https://api.queuewatch.io'));

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), '/api/v1/failures');
        });
    });

    it('does not send if client is not configured', function () {
        Http::fake();

        $job = new SendFailureReport(['test' => 'data']);
        $job->handle(new QueuewatchClient(''));

        Http::assertNothingSent();
    });
});

describe('Queuewatch Test Command', function () {
    it('shows configuration details', function () {
        Http::fake([
            '*' => Http::response(['message' => 'pong'], 200),
        ]);

        $this->artisan('queuewatch:test')
            ->expectsOutputToContain('Configuration')
            ->expectsOutputToContain('test-api')
            ->expectsOutputToContain('Test Project')
            ->assertExitCode(0);
    });

    it('fails when api key is not set', function () {
        config()->set('queuewatch.api_key', null);

        $this->artisan('queuewatch:test')
            ->expectsOutputToContain('API key not configured')
            ->assertExitCode(1);
    });

    it('sends test failure when requested', function () {
        Http::fake([
            '*/ping' => Http::response(['message' => 'pong'], 200),
            '*/failures' => Http::response(['success' => true], 200),
        ]);

        $this->artisan('queuewatch:test --send-test')
            ->expectsOutputToContain('Test failure report sent successfully')
            ->assertExitCode(0);

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), '/failures');
        });
    });
});

describe('Config', function () {
    it('can publish config file', function () {
        $this->artisan('vendor:publish', ['--tag' => 'queuewatch-config'])
            ->assertExitCode(0);
    });

    it('has expected config keys', function () {
        expect(config('queuewatch.enabled'))->not->toBeNull();
        expect(config('queuewatch.endpoint'))->not->toBeNull();
        expect(config('queuewatch.timeout'))->not->toBeNull();
        expect(config('queuewatch.queue'))->not->toBeNull();
        expect(config('queuewatch.collect_job_data'))->not->toBeNull();
    });
});
