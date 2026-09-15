# Changelog

All notable changes to `laravel-enhanced-failed-jobs` will be documented in this file.

## 1.3.1 - 2026-09-15

### Fixed

- `queuewatch:test` now confirms the API key is accepted. It used to check
  only that the API was reachable, through an endpoint that does not
  authenticate, so it reported a successful connection for a wrong key. A
  rejected key now exits with status 1. Against a Queuewatch server without
  the key check, the key is reported as unverified rather than failed.
- `queuewatch:test --send-test` exits with status 1 when the test failure
  report is rejected, instead of printing the error and exiting 0.

### Documentation

- The README now covers the full remote retry setup, including the Retry
  Webhook URL and how retry requests are signed; lists PHP 8.2 as the
  minimum; documents every environment variable; notes that Horizon does not
  run the scheduler; and pins stop reasons to Laravel 12.59.

## 1.3.0 - 2026-09-04

### Added

- Worker monitoring. The package now reports the lifecycle of your queue
  workers to QueueWatch: a run is registered when a worker starts, heartbeats
  are buffered locally while it runs, and how and why it stopped is reported
  when it exits. Enable with `QUEUEWATCH_WORKERS_ENABLED=true`.
- `queuewatch:workers:flush`, a scheduled command that ships buffered
  heartbeats. **Your application's scheduler must be running** — Horizon does
  not run it for you. Without it, heartbeats accumulate in the cache and never
  reach the dashboard. See the Scheduler section of the README.
- Worker type detection, so Horizon, `queue:work` and `queue:listen` are
  distinguished in the dashboard.

### Notes

- Worker monitoring requires **Laravel 12.20 or newer**: the `WorkerStarting`
  event does not exist before that release. On Laravel 10 and 11 every listener
  is guarded, so the package installs and behaves exactly as it did before —
  it simply reports no worker activity.
- Stop **reasons** need **Laravel 12.59.0 or newer**, where `WorkerStopReason`
  and `WorkerStopping::$reason` were backported — not the 12.20 floor. Between
  12.20 and 12.58 a stop is recorded without a reason.
- `jobsProcessed`, `memoryUsage` and `lastJobProcessedAt` on `WorkerStopping`
  need **13.30+**. Below that, jobs processed falls back to this package's own
  count and the other two are omitted. Every field is read through
  `property_exists()`, so no worker fails on an older release.
- Heartbeats need a shared, persistent cache store. With `array` or another
  per-process driver they are written and immediately lost; the package logs a
  warning at boot when it detects one.
- The agent backs off on `403` and `429` responses so an unentitled or
  over-limit account stops sending rather than retrying in a loop.

## 1.2.0 - 2026-09-02

### Added

- Laravel 13 support, tested in CI alongside 10, 11 and 12.

### Removed

- PHP 8.1 support.

## 1.1.1 - 2025-11-30

### Fixed

- The serialized command is included in the reported payload when retry is
  enabled, so the dashboard can rebuild and retry the job.

## 1.1.0 - 2025-11-30

### Added

- Retry endpoint for remote job retries from the QueueWatch dashboard.

### Fixed

- Failures of the internal `SendFailureReport` job are no longer reported,
  which previously could loop when reporting itself failed.

## 1.0.0 - 2025-11-25

- Initial release
- Enhanced `queue:failed` command with JSON output (`--json`)
- Filtering by queue (`--queue`), connection (`--connection`), date range (`--after`, `--before`), and job class (`--class`)
- Result limiting (`--limit`)
- QueueWatch agent for real-time failure reporting (optional)
