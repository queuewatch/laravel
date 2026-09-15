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
            '*/project' => Http::response(['success' => true, 'project' => ['name' => 'Test Project']], 200),
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

describe('Queuewatch Test Command key check', function () {
    it('confirms an accepted api key and names the project', function () {
        Http::fake([
            '*/ping' => Http::response(['message' => 'pong'], 200),
            '*/project' => Http::response(['success' => true, 'project' => ['name' => 'Leadsprout']], 200),
        ]);

        $this->artisan('queuewatch:test')
            // One substring, not two: both appear on the same output line, and
            // the console mock lets a line satisfy only a single expectation.
            ->expectsOutputToContain('API key accepted for project Leadsprout')
            ->assertExitCode(0);
    });

    it('fails when the api key is rejected', function () {
        // The ping endpoint does not authenticate, so a wrong key used to
        // report "Connection successful!" and exit 0.
        Http::fake([
            '*/ping' => Http::response(['message' => 'pong'], 200),
            '*/project' => Http::response(['message' => 'This action is unauthorized.'], 403),
        ]);

        $this->artisan('queuewatch:test')
            ->expectsOutputToContain('API key rejected')
            ->assertExitCode(1);
    });

    it('warns but passes against a server that cannot check keys yet', function () {
        Http::fake([
            '*/ping' => Http::response(['message' => 'pong'], 200),
            '*/project' => Http::response([], 404),
        ]);

        $this->artisan('queuewatch:test')
            ->expectsOutputToContain('Could not verify the API key')
            ->assertExitCode(0);
    });

    it('fails when the key check errors', function () {
        Http::fake([
            '*/ping' => Http::response(['message' => 'pong'], 200),
            '*/project' => Http::response([], 500),
        ]);

        $this->artisan('queuewatch:test')
            ->expectsOutputToContain('Could not verify the API key')
            ->assertExitCode(1);
    });

    it('does not send a test failure once the key is rejected', function () {
        Http::fake([
            '*/ping' => Http::response(['message' => 'pong'], 200),
            '*/project' => Http::response([], 403),
            '*/failures' => Http::response(['success' => true], 201),
        ]);

        $this->artisan('queuewatch:test --send-test')->assertExitCode(1);

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/api/v1/failures'));
    });

    it('fails when the test failure report is rejected', function () {
        // --send-test used to print the failure and still exit 0, so CI could
        // not rely on it either.
        Http::fake([
            '*/ping' => Http::response(['message' => 'pong'], 200),
            '*/project' => Http::response([], 404),
            '*/failures' => Http::response(['message' => 'Monthly failure limit reached'], 429),
        ]);

        $this->artisan('queuewatch:test --send-test')
            ->expectsOutputToContain('Failed to send test report')
            ->assertExitCode(1);
    });
});
