<?php

namespace Queuewatch\Laravel\Commands;

use Illuminate\Console\Command;
use Queuewatch\Laravel\Api\QueuewatchClient;

class QueuewatchTestCommand extends Command
{
    protected $signature = 'queuewatch:test
                            {--send-test : Send a test failure report}';

    protected $description = 'Test the connection to Queuewatch';

    public function handle(QueuewatchClient $client): int
    {
        $this->newLine();
        $this->line('  <fg=cyan>Queuewatch Connection Test</>');
        $this->line('  ─────────────────────────');
        $this->newLine();

        $this->checkConfiguration($client);

        if (! $client->isConfigured()) {
            $this->newLine();
            $this->error('  API key not configured. Add QUEUEWATCH_API_KEY to your .env file.');
            $this->newLine();

            return 1;
        }

        $this->info('  Testing connection to Queuewatch...');
        $this->newLine();

        try {
            $response = $client->testConnection();

            if ($response->successful()) {
                $this->line('  <fg=green>✓</> Connection successful!');

                $data = $response->json();
                if (isset($data['message'])) {
                    $this->line("  <fg=gray>{$data['message']}</>");
                }
            } else {
                $this->line('  <fg=red>✗</> Connection failed');
                $this->line("  <fg=gray>Status: {$response->status()}</>");
                $this->line("  <fg=gray>Response: {$response->body()}</>");
                $this->newLine();

                return 1;
            }
        } catch (\Throwable $e) {
            $this->line('  <fg=red>✗</> Connection failed');
            $this->line("  <fg=gray>Error: {$e->getMessage()}</>");
            $this->newLine();

            return 1;
        }

        if ($this->option('send-test')) {
            $this->newLine();
            $this->sendTestFailure($client);
        }

        $this->newLine();

        return 0;
    }

    protected function checkConfiguration(QueuewatchClient $client): void
    {
        $this->line('  <fg=white>Configuration:</>');
        $this->newLine();

        $apiKey = $client->getApiKey();
        $maskedKey = $apiKey ? substr($apiKey, 0, 8).'...'.substr($apiKey, -4) : '<not set>';

        $this->line("  API Key:     <fg=gray>{$maskedKey}</>");
        $this->line('  Endpoint:    <fg=gray>'.$client->getEndpoint().'</>');
        $this->line('  Project:     <fg=gray>'.config('queuewatch.project', 'Not set').'</>');
        $this->line('  Environment: <fg=gray>'.config('queuewatch.environment', 'Not set').'</>');
        $this->line('  Enabled:     <fg=gray>'.(config('queuewatch.enabled') ? 'Yes' : 'No').'</>');
        $this->line('  Queue:       <fg=gray>'.config('queuewatch.queue', 'default').'</>');
        $this->newLine();
    }

    protected function sendTestFailure(QueuewatchClient $client): void
    {
        $this->info('  Sending test failure report...');

        $payload = [
            'project' => config('queuewatch.project', config('app.name')),
            'environment' => config('queuewatch.environment', config('app.env')),
            'job' => [
                'id' => 'test-'.uniqid(),
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'name' => 'QueuewatchTestJob',
                'class' => 'Queuewatch\\Laravel\\Commands\\QueuewatchTestCommand',
                'queue' => 'default',
                'connection' => 'sync',
                'attempts' => 1,
                'max_tries' => 3,
                'timeout' => null,
            ],
            'exception' => [
                'class' => 'Exception',
                'message' => 'This is a test failure from queuewatch:test command',
                'code' => 0,
                'file' => __FILE__,
                'line' => __LINE__,
                'trace' => [],
            ],
            'failed_at' => now()->toIso8601String(),
            'server' => [
                'hostname' => gethostname(),
                'php_version' => PHP_VERSION,
                'laravel_version' => app()->version(),
            ],
            'is_test' => true,
        ];

        try {
            $response = $client->reportFailure($payload);

            if ($response->successful()) {
                $this->line('  <fg=green>✓</> Test failure report sent successfully!');
                $this->line('  <fg=gray>Check your Queuewatch dashboard to see the test failure.</>');
            } else {
                $this->line('  <fg=red>✗</> Failed to send test report');
                $this->line("  <fg=gray>Status: {$response->status()}</>");
                $this->line("  <fg=gray>Response: {$response->body()}</>");
            }
        } catch (\Throwable $e) {
            $this->line('  <fg=red>✗</> Failed to send test report');
            $this->line("  <fg=gray>Error: {$e->getMessage()}</>");
        }
    }
}
