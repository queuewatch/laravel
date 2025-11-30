<?php

return [

    /*
    |--------------------------------------------------------------------------
    | QueueWatch API Key
    |--------------------------------------------------------------------------
    |
    | Your QueueWatch API key. You can find this in your QueueWatch dashboard
    | settings. If not set, the agent will be disabled and only local CLI
    | tools will be available.
    |
    */

    'api_key' => env('QUEUEWATCH_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Project Name
    |--------------------------------------------------------------------------
    |
    | The name of this project/application. This helps identify which app
    | the failed jobs came from in your QueueWatch dashboard.
    |
    */

    'project' => env('QUEUEWATCH_PROJECT', env('APP_NAME', 'Laravel')),

    /*
    |--------------------------------------------------------------------------
    | Environment
    |--------------------------------------------------------------------------
    |
    | The environment name to report. Useful for filtering between
    | production, staging, and local failures in the dashboard.
    |
    */

    'environment' => env('QUEUEWATCH_ENVIRONMENT', env('APP_ENV', 'production')),

    /*
    |--------------------------------------------------------------------------
    | QueueWatch API Endpoint
    |--------------------------------------------------------------------------
    |
    | The endpoint where failed job reports are sent. You shouldn't need
    | to change this unless you're using a self-hosted QueueWatch instance.
    |
    */

    'endpoint' => env('QUEUEWATCH_ENDPOINT', 'https://api.queuewatch.io'),

    /*
    |--------------------------------------------------------------------------
    | Reporting Queue
    |--------------------------------------------------------------------------
    |
    | The queue to use for sending failure reports. Using a queue ensures
    | that reporting doesn't slow down your application. Set to 'sync'
    | to send reports immediately (not recommended for production).
    |
    */

    'queue' => env('QUEUEWATCH_QUEUE', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connection
    |--------------------------------------------------------------------------
    |
    | The queue connection to use for sending failure reports. Leave null
    | to use the default queue connection.
    |
    */

    'queue_connection' => env('QUEUEWATCH_QUEUE_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | Enable or disable the QueueWatch agent. When disabled, no reports
    | will be sent to the QueueWatch API. The CLI tools will still work.
    |
    */

    'enabled' => env('QUEUEWATCH_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Ignored Jobs
    |--------------------------------------------------------------------------
    |
    | An array of job class names that should not be reported to QueueWatch.
    | Useful for ignoring noisy jobs that fail frequently but aren't critical.
    |
    */

    'ignored_jobs' => [
        // App\Jobs\NoisyJob::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Ignored Queues
    |--------------------------------------------------------------------------
    |
    | An array of queue names that should not be reported to QueueWatch.
    |
    */

    'ignored_queues' => [
        // 'low-priority',
    ],

    /*
    |--------------------------------------------------------------------------
    | Ignored Exceptions
    |--------------------------------------------------------------------------
    |
    | An array of exception class names that should not be reported.
    |
    */

    'ignored_exceptions' => [
        // Illuminate\Database\Eloquent\ModelNotFoundException::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Collect Job Data
    |--------------------------------------------------------------------------
    |
    | Whether to include the full job payload in reports. Disabling this
    | can help with privacy/security if your jobs contain sensitive data.
    |
    */

    'collect_job_data' => env('QUEUEWATCH_COLLECT_JOB_DATA', true),

    /*
    |--------------------------------------------------------------------------
    | Timeout
    |--------------------------------------------------------------------------
    |
    | The timeout in seconds for API requests to QueueWatch.
    |
    */

    'timeout' => env('QUEUEWATCH_TIMEOUT', 5),

    /*
    |--------------------------------------------------------------------------
    | Retry Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the retry functionality that allows QueueWatch to trigger
    | job retries remotely from the dashboard.
    |
    */

    'retry' => [

        /*
        |--------------------------------------------------------------------------
        | Enable Retry Endpoint
        |--------------------------------------------------------------------------
        |
        | Whether to enable the retry endpoint. When enabled, QueueWatch can
        | send retry requests to re-dispatch failed jobs.
        |
        */

        'enabled' => env('QUEUEWATCH_RETRY_ENABLED', false),

        /*
        |--------------------------------------------------------------------------
        | Retry Route Path
        |--------------------------------------------------------------------------
        |
        | The path where the retry endpoint will be registered. The full URL
        | will be: https://your-app.com/{path}
        |
        */

        'path' => env('QUEUEWATCH_RETRY_PATH', 'queuewatch/retry'),

        /*
        |--------------------------------------------------------------------------
        | Retry Route Middleware
        |--------------------------------------------------------------------------
        |
        | Middleware to apply to the retry route. By default, no middleware
        | is applied since authentication is handled via HMAC signature.
        |
        */

        'middleware' => [],

        /*
        |--------------------------------------------------------------------------
        | Allowed Queues
        |--------------------------------------------------------------------------
        |
        | Limit which queues can be retried. Use ['*'] to allow all queues,
        | or specify an array of queue names like ['payments', 'emails'].
        |
        */

        'allowed_queues' => ['*'],

        /*
        |--------------------------------------------------------------------------
        | Retry Delay
        |--------------------------------------------------------------------------
        |
        | Delay in seconds before the retried job runs. Set to 0 for immediate
        | execution. Useful when retrying jobs that failed due to external
        | service issues.
        |
        */

        'delay' => env('QUEUEWATCH_RETRY_DELAY', 0),

    ],

];
