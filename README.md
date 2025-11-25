# Laravel Enhanced Failed Jobs

[![Latest Version on Packagist](https://img.shields.io/packagist/v/mvpopuk/laravel-enhanced-failed-jobs.svg?style=flat-square)](https://packagist.org/packages/mvpopuk/laravel-enhanced-failed-jobs)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/mvpopuk/laravel-enhanced-failed-jobs/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/mvpopuk/laravel-enhanced-failed-jobs/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/mvpopuk/laravel-enhanced-failed-jobs.svg?style=flat-square)](https://packagist.org/packages/mvpopuk/laravel-enhanced-failed-jobs)

Enhanced `queue:failed` command with JSON output, advanced filtering, and optional QueueWatch dashboard integration (soon).

## Features

- **CLI Tools** (Free & Open Source)
  - Advanced filtering by queue, connection, date range, and job class
  - JSON output for CI/CD pipelines and automation
  - Partial class name matching
  - Result limiting

- **QueueWatch Integration** (Optional SaaS) - WIP
  - Real-time failure reporting to QueueWatch dashboard
  - Multi-app monitoring from a single dashboard
  - Slack, Discord, and PagerDuty alerts
  - Analytics and failure trends

## Installation

```bash
composer require mvpopuk/laravel-enhanced-failed-jobs
```

The package will automatically register itself.

## CLI Usage

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

### CI/CD Examples

```bash
# Fail deployment if critical jobs failed
FAILED_COUNT=$(php artisan queue:failed --json | jq '.count')
if [ "$FAILED_COUNT" -gt 0 ]; then
  echo "Found $FAILED_COUNT failed jobs"
  exit 1
fi

# Get failed jobs from specific queue in last hour
php artisan queue:failed \
  --queue=critical \
  --after="1 hour ago" \
  --json
```

---

## QueueWatch Dashboard Integration

WIP

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
