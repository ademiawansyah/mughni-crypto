<?php

namespace App\Http\Controllers;

use App\Jobs\RunDeployScriptJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GitHubWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $signature = (string) $request->header('X-Hub-Signature-256', '');
        $secret = (string) config('deployment.webhook_secret', '');

        if ($secret === '' || $signature === '' || ! $this->isValidSignature($request->getContent(), $signature, $secret)) {
            Log::warning('[GitHubWebhook] Invalid signature', [
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => 'Invalid signature'], 403);
        }

        $event = (string) $request->header('X-GitHub-Event', '');
        if ($event !== 'push') {
            return response()->json(['message' => 'Ignored event'], 202);
        }

        $payload = $request->json()->all();
        $ref = (string) ($payload['ref'] ?? '');
        $repository = (string) ($payload['repository']['full_name'] ?? '');

        $allowedBranch = (string) config('deployment.allowed_branch', 'main');
        $allowedRef = sprintf('refs/heads/%s', $allowedBranch);
        if ($ref !== $allowedRef) {
            Log::info('[GitHubWebhook] Ignored branch', [
                'ref' => $ref,
                'expected_ref' => $allowedRef,
            ]);

            return response()->json(['message' => 'Ignored branch'], 202);
        }

        $allowedRepository = (string) config('deployment.allowed_repository', '');
        if ($allowedRepository !== '' && $repository !== $allowedRepository) {
            Log::warning('[GitHubWebhook] Ignored repository', [
                'repository' => $repository,
                'expected_repository' => $allowedRepository,
            ]);

            return response()->json(['message' => 'Ignored repository'], 202);
        }

        RunDeployScriptJob::dispatch($payload)
            ->onQueue((string) config('deployment.queue', 'default'));

        return response()->json(['message' => 'Deployment queued'], 200);
    }

    private function isValidSignature(string $payload, string $providedSignature, string $secret): bool
    {
        $expectedSignature = 'sha256=' . hash_hmac('sha256', $payload, $secret);

        return hash_equals($expectedSignature, $providedSignature);
    }
}
