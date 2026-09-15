# Queuewatch Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/queuewatch/laravel.svg?style=flat-square)](https://packagist.org/packages/queuewatch/laravel)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/queuewatch/laravel/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/queuewatch/laravel/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/queuewatch/laravel.svg?style=flat-square)](https://packagist.org/packages/queuewatch/laravel)

## Enhanced CLI

## Installation

```bash
composer require queuewatch/laravel
```

### Basic Commands

```bash
# List all failed jobs (table format)
php artisan queue:failed

# Output as JSON
php artisan queue:failed --json
```

### Filtering Options

```bash
# Filter by queue name
php artisan queue:failed --queue=emails

# Filter by connection
php artisan queue:failed --connection=redis

# Filter by date range
php artisan queue:failed --after="2025-11-20"
php artisan queue:failed --before="2025-11-21"
php artisan queue:failed --after=yesterday --before=today

# Filter by job class (partial match)
php artisan queue:failed --class=SendEmail

# Limit results
php artisan queue:failed --limit=50

# Combine multiple filters
php artisan queue:failed --queue=emails --after=yesterday --limit=10 --json
```

### JSON Output Format

```json
{
  "failed_jobs": [
    {
      "id": "1234",
      "uuid": "550e8400-e29b-41d4-a716-446655440000",
      "connection": "redis",
      "queue": "emails",
      "payload": {
        "displayName": "App\\Jobs\\SendEmail",
        "job": "Illuminate\\Queue\\CallQueuedHandler@call",
        "data": {}
      },
      "exception": "Connection timeout...",
      "failed_at": "2025-11-21 10:30:00"
    }
  ],
  "count": 1
}
```

## [Queuewatch.io](https://queuewatch.io) (Optional)

Official Laravel package for [Queuewatch](https://queuewatch.io) - Real-time queue failure monitoring with instant notifications.

- **Real-time Failure Reporting** - Automatically capture and report queue job failures to your Queuewatch dashboard
- **Rich Exception Data** - Full stack traces, job payloads, and server context
- **Smart Filtering** - Ignore specific jobs, queues, or exception types
- **Worker Monitoring** - See which queue workers are running, when they last checked in, and why they stopped (Laravel 12.20+)
- **Remote Retry** - Retry failed jobs directly from the Queuewatch dashboard
- **Instant Notifications** - Get notified via Slack, Discord, email, or webhooks when jobs fail

<img width="1200" height="630" alt="Banners Frame 2" src="https://github.com/user-attachments/assets/8efc68c0-f3e8-499a-bb53-955c31ce85f8" />

## Requirements

- PHP 8.2+
- Laravel 10.x, 11.x, 12.x, or 13.x
- A [Queuewatch](https://queuewatch.io) account

## Installation

```bash
composer require queuewatch/laravel
```

Add your API key to `.env`:

```env
QUEUEWATCH_API_KEY=your-project-api-key
```

That's it! The package automatically hooks into Laravel's queue system and starts reporting failures.

## Getting Your API Key

1. Sign up at [queuewatch.io](https://queuewatch.io)
2. Create a new project in your dashboard
3. Open the project and copy the key from its **API Key** card
4. Add it to your `.env` file

## Configuration

Publish the config file for advanced customization:

```bash
php artisan vendor:publish --tag=queuewatch-config
```

### Available Options

```php
// config/queuewatch.php
return [
    'enabled' => env('QUEUEWATCH_ENABLED', true),
    'api_key' => env('QUEUEWATCH_API_KEY'),
    'project' => env('QUEUEWATCH_PROJECT', env('APP_NAME')),
    'environment' => env('QUEUEWATCH_ENVIRONMENT', env('APP_ENV')),

    // Jobs to ignore
    'ignored_jobs' => [
        // App\Jobs\NoisyJob::class,
    ],

    // Queues to ignore
    'ignored_queues' => [
        // 'low-priority',
    ],

    // Exceptions to ignore
    'ignored_exceptions' => [
        // Illuminate\Database\Eloquent\ModelNotFoundException::class,
    ],
];
```

### Environment Variables

| Variable | Description | Default |
|----------|-------------|---------|
| `QUEUEWATCH_ENABLED` | Enable/disable failure reporting | `true` |
| `QUEUEWATCH_API_KEY` | Your Queuewatch API key | - |
| `QUEUEWATCH_PROJECT` | Project name in dashboard | `APP_NAME` |
| `QUEUEWATCH_ENVIRONMENT` | Environment label (production, staging, etc.) | `APP_ENV` |
| `QUEUEWATCH_RETRY_ENABLED` | Enable remote retry feature | `false` |
| `QUEUEWATCH_RETRY_PATH` | Path of the retry endpoint | `queuewatch/retry` |
| `QUEUEWATCH_RETRY_DELAY` | Seconds to delay a retried job | `0` |
| `QUEUEWATCH_COLLECT_JOB_DATA` | Include the job payload in failure reports | `true` |
| `QUEUEWATCH_QUEUE` | Queue used to send reports | `default` |
| `QUEUEWATCH_QUEUE_CONNECTION` | Queue connection used to send reports | default connection |
| `QUEUEWATCH_TIMEOUT` | API request timeout, in seconds | `5` |
| `QUEUEWATCH_ENDPOINT` | API endpoint — only change for a self-hosted instance | `https://api.queuewatch.io` |
| `QUEUEWATCH_WORKERS_ENABLED` | Enable worker monitoring | `false` |
| `QUEUEWATCH_WORKER_HEARTBEAT_INTERVAL` | Seconds between worker heartbeats | `15` |
| `QUEUEWATCH_WORKER_CACHE_STORE` | Cache store for the heartbeat buffer | default store |

## Testing Your Integration

```bash
php artisan queuewatch:test
```

This verifies your API key and connection. Add `--send-test` to send a test failure:

```bash
php artisan queuewatch:test --send-test
```

You should see the test failure appear in your [Queuewatch dashboard](https://queuewatch.io/dashboard) within seconds.

## What Gets Reported

When a job fails, Queuewatch captures:

- **Job Details** - Class name, queue, connection, attempts, max tries
- **Exception** - Message, class, file, line, full stack trace
- **Payload** - Complete job payload (can be disabled for sensitive data)
- **Context** - Server hostname, PHP version, Laravel version, timestamp
- **Environment** - Your configured environment label

## Remote Retry

Retry a failed job straight from the Queuewatch dashboard. Available on the Pro plan and above.

1. Enable the retry endpoint in your application:

   ```env
   QUEUEWATCH_RETRY_ENABLED=true
   ```

   This registers a `POST` route at `/queuewatch/retry` (change the path with `QUEUEWATCH_RETRY_PATH`).

2. In the Queuewatch dashboard, edit your project and set **Retry Webhook URL** to that route's full address, for example `https://your-app.com/queuewatch/retry`. The Retry button does not appear until this is set.

**Only failures reported after you enable retry can be retried.** Rebuilding a job needs its serialized command, and the package only includes it in failure reports while `QUEUEWATCH_RETRY_ENABLED` is `true`. Failures captured before then show a note in the dashboard instead of a Retry button.

### How retry requests are verified

Each retry request is signed with your project's API key: the `X-Queuewatch-Signature` header carries an HMAC-SHA256 of the JSON payload. The package checks it against `QUEUEWATCH_API_KEY` and rejects a mismatch with a `401`. There is no separate retry secret, so protect the API key accordingly — and if you regenerate it, update your `.env`, or retries will be rejected.

### Options

```php
'retry' => [
    'enabled' => env('QUEUEWATCH_RETRY_ENABLED', false),
    'path' => env('QUEUEWATCH_RETRY_PATH', 'queuewatch/retry'),

    // Extra middleware for the retry route
    'middleware' => [],

    // Queues that may be retried; a request for any other queue gets a 403
    'allowed_queues' => ['*'],

    // Seconds to delay the re-dispatched job
    'delay' => env('QUEUEWATCH_RETRY_DELAY', 0),
],
```

The job is re-dispatched to the connection and queue it originally failed on.

## Worker Monitoring

Report queue worker lifecycle (start, heartbeat, stop) to Queuewatch so you can see which workers are running, when they last checked in, and why they stopped. This is opt-in: it is disabled by default, so upgrading this package introduces no new behaviour until you turn it on.

**Requires Laravel 12.20 or newer.** `Illuminate\Queue\Events\WorkerStarting` — the event this feature relies on to detect a worker starting up — was not introduced until Laravel 12.20. On Laravel 10.x, 11.x, and 12.0-12.19, enabling worker monitoring reports nothing at all: no run is ever created, so every other lifecycle handler stays inert. A warning is logged when the application boots if you enable worker monitoring on an unsupported version.

```env
QUEUEWATCH_WORKERS_ENABLED=true
QUEUEWATCH_API_KEY=your-project-api-key
```

Both variables are required — worker monitoring stays off if either `QUEUEWATCH_WORKERS_ENABLED` is false or `QUEUEWATCH_API_KEY` is empty. You can also tune how often a running worker reports in:

```env
QUEUEWATCH_WORKER_HEARTBEAT_INTERVAL=15
```

### Scheduler

Heartbeats are buffered locally by the worker process and flushed to Queuewatch by a scheduled command, so your app's scheduler must be running. **This applies to Horizon too** — Horizon runs your queue workers, but not the scheduler, so it still needs its own cron entry (or `php artisan schedule:work`):

```
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

The package registers `queuewatch:workers:flush` to run `everyMinute()` for you — you don't need to add it to your own schedule.

### Cache store

The heartbeat buffer is written by the worker process and read by the scheduled flush command, so it needs a cache store that is shared and persists between processes — Redis, Memcached, DynamoDB, or a database store all work. The `array` and `null` drivers do not survive between processes and will silently lose every heartbeat; if your default cache store can't be used for this, set a dedicated one:

```env
QUEUEWATCH_WORKER_CACHE_STORE=redis
```

If the configured store can't survive between processes, a warning is logged when the application boots.

### Stop reasons

Worker start, heartbeat, and stop reporting all share the same Laravel 12.20+ floor described above — narrower than the ^10–^13 range this package otherwise supports. Beyond that floor the queue worker API gained capabilities in two further steps, so what you get depends on your exact Laravel version:

| | 12.20 – 12.58 | 12.59 – 13.29 | 13.30+ |
|---|---|---|---|
| Worker runs, heartbeats, liveness | yes | yes | yes |
| Stop **reason** (`WorkerStopReason`) | no | yes | yes |
| `jobsProcessed`, `memoryUsage`, `lastJobProcessedAt` | no | no | yes |

`WorkerStopReason` and `WorkerStopping::$reason` were backported to Laravel **12.59.0** (released 2026-05-14), not at the 12.20 floor. Below that a stop is still recorded — you see that the worker exited and when — but the reason is null.

The richer `WorkerStopping` payload arrived in **13.30**. Between 12.59 and 13.30 a stop is reported with its reason, jobs processed falls back to this package's own in-process count, and memory usage and last-job-processed-at are omitted.

Every one of these fields is read through `property_exists()`, so a worker never fails because its Laravel version predates a field.

Note that `WorkerStopReason::description()` exists **only** in 13.30+ — the 12.x backport ships the enum without it. This package therefore never calls it; the human-readable descriptions are derived by QueueWatch from the reason value instead, which is what lets a single package version serve every release above the floor.

## Notifications

Configure notifications in your [Queuewatch dashboard](https://queuewatch.io/dashboard/settings/notifications):

- **Slack** - Get alerts in your team's Slack channel
- **Discord** - Send notifications to Discord webhooks
- **Email** - Receive email alerts for failures
- **Webhooks** - Integrate with any service via custom webhooks

Set up notification rules to filter by environment, job type, or failure frequency.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Marian Pop](https://github.com/mvpopuk)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
