<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class RunDeployScriptJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public int $tries = 1;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        private readonly array $payload,
    ) {}

    public function handle(): void
    {
        $scriptPath = (string) config('deployment.script_path');
        $processTimeout = (int) config('deployment.script_timeout', 600);

        if ($scriptPath === '') {
            Log::channel('deployment')->error('[DeployJob] Missing script path');

            return;
        }

        $commit = (string) ($this->payload['after'] ?? '');
        $repository = (string) ($this->payload['repository']['full_name'] ?? '');

        $env = [
            'DEPLOY_COMMIT' => $commit,
            'DEPLOY_REPOSITORY' => $repository,
            'DEPLOY_BRANCH' => (string) config('deployment.allowed_branch', 'main'),
            'DEPLOY_TRIGGER_PATH' => (string) config('deployment.trigger_path', storage_path('logs/deploy.trigger')),
            'DEPLOY_HOST_TOKEN' => (string) config('deployment.host_token', ''),
        ];

        $process = new Process(['bash', $scriptPath], base_path(), $env, null, $processTimeout);

        $process->run();

        if (! $process->isSuccessful()) {
            Log::channel('deployment')->error('[DeployJob] Script failed', [
                'exit_code' => $process->getExitCode(),
                'output' => $process->getOutput(),
                'error_output' => $process->getErrorOutput(),
            ]);

            throw new \RuntimeException('Deployment script failed.');
        }

        Log::channel('deployment')->info('[DeployJob] Script completed', [
            'commit' => $commit,
            'repository' => $repository,
            'output' => $process->getOutput(),
        ]);
    }
}
