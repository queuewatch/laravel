<img width="2412" height="1654" alt="CleanShot 2025-12-10 at 14 53 13@2x" src="https://github.com/user-attachments/assets/73bbe659-b924-459a-8c6d-9325cc3bdf82" />


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
- **Remote Retry** - Retry failed jobs directly from the Queuewatch dashboard
- **Instant Notifications** - Get notified via Slack, Discord, email, or webhooks when jobs fail

<img width="1200" height="630" alt="Banners Frame 2" src="https://github.com/user-attachments/assets/8efc68c0-f3e8-499a-bb53-955c31ce85f8" />

## Requirements

- PHP 8.1+
- Laravel 10.x, 11.x, or 12.x
- A [Queuewatch](https://queuewatch.io) account

## Installation

```bash
composer require queuewatch/laravel
```

Add your API key to `.env`:

```env
QUEUEWATCH_API_KEY=qw_live_xxxxxxxxxxxxxxxxxxxx
```

That's it! The package automatically hooks into Laravel's queue system and starts reporting failures.

## Getting Your API Key

1. Sign up at [queuewatch.io](https://queuewatch.io)
2. Create a new project in your dashboard
3. Copy the API key from **Settings → API Keys**
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

Enable remote retry to retry failed jobs directly from the Queuewatch dashboard:

```env
QUEUEWATCH_RETRY_ENABLED=true
```

Configure allowed queues for security:

```php
'retry' => [
    'enabled' => env('QUEUEWATCH_RETRY_ENABLED', false),
    'allowed_queues' => ['payments', 'emails'], // or ['*'] for all
],
```

When enabled, you can click "Retry" on any failed job in your Queuewatch dashboard, and it will be re-dispatched to your Laravel application.

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
