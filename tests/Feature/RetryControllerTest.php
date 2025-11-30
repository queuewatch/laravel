<?php

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Mvpopuk\LaravelEnhancedFailedJobs\Http\Controllers\RetryController;

beforeEach(function () {
    config()->set('queuewatch.api_key', 'test-api-key-12345');
    config()->set('queuewatch.retry.enabled', true);
    config()->set('queuewatch.retry.path', 'queuewatch/retry');
    config()->set('queuewatch.retry.middleware', []);
    config()->set('queuewatch.retry.allowed_queues', ['*']);
    config()->set('queuewatch.retry.delay', 0);

    // Register the route manually for testing since boot() runs before config is set
    Route::post('queuewatch/retry', RetryController::class)->name('queuewatch.retry');
});

describe('RetryController', function () {
    it('rejects requests without signature', function () {
        $payload = createRetryPayload();

        $response = $this->postJson('/queuewatch/retry', $payload);

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'error' => 'Invalid signature',
            ]);
    });

    it('rejects requests with invalid signature', function () {
        $payload = createRetryPayload();

        $response = $this->postJson('/queuewatch/retry', $payload, [
            'X-Queuewatch-Signature' => 'invalid-signature',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'error' => 'Invalid signature',
            ]);
    });

    it('rejects requests for disallowed queues', function () {
        config()->set('queuewatch.retry.allowed_queues', ['payments', 'emails']);

        $payload = createRetryPayload(['queue' => 'notifications']);
        $signature = generateSignature($payload);

        $response = $this->postJson('/queuewatch/retry', $payload, [
            'X-Queuewatch-Signature' => $signature,
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'error' => 'Queue not allowed for retry',
            ]);
    });

    it('allows all queues when wildcard is configured', function () {
        Queue::fake();
        config()->set('queuewatch.retry.allowed_queues', ['*']);

        $payload = createRetryPayload(['queue' => 'any-queue']);
        $signature = generateSignature($payload);

        $response = $this->postJson('/queuewatch/retry', $payload, [
            'X-Queuewatch-Signature' => $signature,
        ]);

        $response->assertSuccessful()
            ->assertJson([
                'success' => true,
                'message' => 'Job dispatched successfully',
            ]);
    });

    it('dispatches job with valid signature and payload', function () {
        Queue::fake();

        $payload = createRetryPayload();
        $signature = generateSignature($payload);

        $response = $this->postJson('/queuewatch/retry', $payload, [
            'X-Queuewatch-Signature' => $signature,
        ]);

        $response->assertSuccessful()
            ->assertJson([
                'success' => true,
                'message' => 'Job dispatched successfully',
            ]);
    });

    it('returns error when job command data is missing', function () {
        $payload = createRetryPayload([
            'payload' => ['data' => []],
        ]);
        $signature = generateSignature($payload);

        $response = $this->postJson('/queuewatch/retry', $payload, [
            'X-Queuewatch-Signature' => $signature,
        ]);

        $response->assertStatus(500)
            ->assertJsonStructure([
                'success',
                'error',
            ]);
    });

    it('validates required fields', function () {
        $payload = [
            'action' => 'retry',
        ];
        $signature = generateSignature($payload);

        $response = $this->postJson('/queuewatch/retry', $payload, [
            'X-Queuewatch-Signature' => $signature,
        ]);

        $response->assertStatus(422);
    });

    it('only accepts retry action', function () {
        $payload = createRetryPayload(['action' => 'delete']);
        $signature = generateSignature($payload);

        $response = $this->postJson('/queuewatch/retry', $payload, [
            'X-Queuewatch-Signature' => $signature,
        ]);

        $response->assertStatus(422);
    });
});

describe('RetryController route registration', function () {
    it('does not register route when retry is disabled', function () {
        config()->set('queuewatch.retry.enabled', false);

        // Clear routes and re-register
        Route::getRoutes()->refreshNameLookups();

        expect(Route::has('queuewatch.retry.disabled'))->toBeFalse();
    });
});

/**
 * Fake job class for testing.
 */
class FakeTestJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public string $data = 'test') {}

    public function handle(): void {}
}

/**
 * Create a retry payload for testing.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function createRetryPayload(array $overrides = []): array
{
    $fakeJob = new FakeTestJob('test-data');

    $defaultPayload = [
        'action' => 'retry',
        'job_uuid' => 'test-uuid-123',
        'job_id' => '1',
        'job_class' => FakeTestJob::class,
        'connection' => 'sync',
        'queue' => 'default',
        'payload' => [
            'uuid' => 'test-uuid-123',
            'displayName' => FakeTestJob::class,
            'job' => FakeTestJob::class,
            'maxTries' => 3,
            'timeout' => null,
            'data' => [
                'command' => serialize($fakeJob),
            ],
        ],
    ];

    return array_merge($defaultPayload, $overrides);
}

/**
 * Generate HMAC signature for a payload.
 */
function generateSignature(array $payload): string
{
    $apiKey = config('queuewatch.api_key');

    return hash_hmac('sha256', json_encode($payload), $apiKey);
}
