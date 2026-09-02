<?php

use Illuminate\Queue\Failed\DatabaseFailedJobProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->app['config']->set('queue.failed.database', 'testing');
    $this->app['config']->set('queue.failed.table', 'failed_jobs');
    $this->app['config']->set('queue.failed.driver', 'database');

    $this->app['db']->connection()->getSchemaBuilder()->create('failed_jobs', function ($table) {
        $table->id();
        $table->string('uuid')->unique()->nullable();
        $table->text('connection');
        $table->text('queue');
        $table->longText('payload');
        $table->longText('exception');
        $table->timestamp('failed_at')->useCurrent();
    });

    $this->app->singleton('queue.failer', function ($app) {
        return new DatabaseFailedJobProvider(
            $app['db'],
            $app['config']['queue.failed.database'],
            $app['config']['queue.failed.table']
        );
    });
});

afterEach(function () {
    $this->app['db']->connection()->getSchemaBuilder()->dropIfExists('failed_jobs');
});

it('shows info message when no failed jobs', function () {
    $this->artisan('queue:failed')
        ->expectsOutput('No failed jobs!')
        ->assertExitCode(0);
});

it('outputs empty json when no failed jobs with json flag', function () {
    Artisan::call('queue:failed', ['--json' => true]);
    $output = Artisan::output();

    expect($output)->toContain('failed_jobs');
    expect($output)->toContain('count');
});

it('displays failed jobs in table format', function () {
    insertFailedJob();

    $this->artisan('queue:failed')
        ->expectsOutputToContain('default')
        ->assertExitCode(0);
});

it('outputs failed jobs as json', function () {
    insertFailedJob();

    Artisan::call('queue:failed', ['--json' => true]);
    $output = Artisan::output();

    expect($output)->toContain('failed_jobs');
    expect($output)->toContain('database');
    expect($output)->toContain('default');
});

it('includes full payload in json output', function () {
    insertFailedJob();

    Artisan::call('queue:failed', ['--json' => true]);
    $output = Artisan::output();

    expect($output)->toContain('payload');
    expect($output)->toContain('displayName');
});

it('can filter by queue name', function () {
    insertFailedJob(['queue' => 'emails']);
    insertFailedJob(['queue' => 'notifications']);

    $this->artisan('queue:failed --queue=emails')
        ->expectsOutputToContain('emails')
        ->assertExitCode(0);
});

it('shows message when no jobs match queue filter', function () {
    insertFailedJob(['queue' => 'emails']);

    $this->artisan('queue:failed --queue=nonexistent')
        ->expectsOutput('No failed jobs match the given criteria.')
        ->assertExitCode(0);
});

it('can filter by connection name', function () {
    insertFailedJob(['connection' => 'redis']);
    insertFailedJob(['connection' => 'database']);

    Artisan::call('queue:failed', ['--connection' => 'redis', '--json' => true]);
    $output = Artisan::output();

    expect($output)->toContain('redis');
});

it('can filter by job class name using partial match', function () {
    insertFailedJob(['payload' => json_encode(['displayName' => 'App\\Jobs\\SendEmailNotification'])]);
    insertFailedJob(['payload' => json_encode(['displayName' => 'App\\Jobs\\ProcessPayment'])]);

    $this->artisan('queue:failed --class=Email')
        ->expectsOutputToContain('SendEmailNotification')
        ->assertExitCode(0);
});

it('can filter by after date', function () {
    insertFailedJob(['failed_at' => now()->subDays(5)->toDateTimeString()]);
    insertFailedJob(['failed_at' => now()->subDay()->toDateTimeString()]);

    Artisan::call('queue:failed', ['--after' => '2 days ago', '--json' => true]);
    $output = Artisan::output();

    expect($output)->toContain('failed_jobs');
});

it('can filter by before date', function () {
    insertFailedJob(['failed_at' => now()->subDays(5)->toDateTimeString()]);
    insertFailedJob(['failed_at' => now()->subDay()->toDateTimeString()]);

    Artisan::call('queue:failed', ['--before' => '3 days ago', '--json' => true]);
    $output = Artisan::output();

    expect($output)->toContain('failed_jobs');
});

it('can filter by date range', function () {
    insertFailedJob(['failed_at' => now()->subDays(10)->toDateTimeString()]);
    insertFailedJob(['failed_at' => now()->subDays(3)->toDateTimeString()]);
    insertFailedJob(['failed_at' => now()->toDateTimeString()]);

    Artisan::call('queue:failed', ['--after' => '5 days ago', '--before' => '1 day ago', '--json' => true]);
    $output = Artisan::output();

    expect($output)->toContain('failed_jobs');
});

it('can limit results', function () {
    insertFailedJob();
    insertFailedJob();
    insertFailedJob();

    Artisan::call('queue:failed', ['--limit' => 2, '--json' => true]);
    $output = Artisan::output();
    $data = json_decode($output, true);

    expect($data['count'])->toBe(2);
});

it('can combine multiple filters', function () {
    insertFailedJob(['queue' => 'emails', 'connection' => 'redis']);
    insertFailedJob(['queue' => 'emails', 'connection' => 'database']);
    insertFailedJob(['queue' => 'notifications', 'connection' => 'redis']);

    Artisan::call('queue:failed', ['--queue' => 'emails', '--connection' => 'redis', '--json' => true]);
    $output = Artisan::output();

    expect($output)->toContain('redis');
    expect($output)->toContain('emails');
});

it('supports relative date formats', function () {
    insertFailedJob(['failed_at' => now()->toDateTimeString()]);

    $this->artisan('queue:failed --after=yesterday')
        ->expectsOutputToContain('default')
        ->assertExitCode(0);

    $this->artisan('queue:failed --after="1 hour ago"')
        ->expectsOutputToContain('default')
        ->assertExitCode(0);
});

it('shows total count in table output', function () {
    insertFailedJob();
    insertFailedJob();

    $this->artisan('queue:failed')
        ->expectsOutputToContain('Total failed jobs: 2')
        ->assertExitCode(0);
});

it('returns empty json array when filters match nothing', function () {
    insertFailedJob(['queue' => 'emails']);

    Artisan::call('queue:failed', ['--queue' => 'nonexistent', '--json' => true]);
    $output = Artisan::output();
    $data = json_decode($output, true);

    expect($data['count'])->toBe(0);
    expect($data['failed_jobs'])->toBe([]);
});

it('json output includes all expected fields', function () {
    insertFailedJob();

    Artisan::call('queue:failed', ['--json' => true]);
    $output = Artisan::output();
    $data = json_decode($output, true);

    expect($data)->toHaveKey('failed_jobs');
    expect($data)->toHaveKey('count');
    expect($data['count'])->toBe(1);
    expect($data['failed_jobs'][0])->toHaveKeys(['id', 'uuid', 'connection', 'queue', 'payload', 'exception', 'failed_at']);
});

/**
 * Helper function to insert a failed job.
 */
function insertFailedJob(array $overrides = []): void
{
    $defaults = [
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode([
            'displayName' => 'App\\Jobs\\SendEmail',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => ['command' => 'serialized data'],
        ]),
        'exception' => 'Exception: Test exception message',
        'failed_at' => now()->toDateTimeString(),
    ];

    $data = array_merge($defaults, $overrides);

    DB::table('failed_jobs')->insert($data);
}
