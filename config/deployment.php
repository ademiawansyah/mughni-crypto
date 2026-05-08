<?php

return [
    'webhook_secret' => env('GITHUB_WEBHOOK_SECRET', ''),
    'allowed_branch' => env('GITHUB_DEPLOY_BRANCH', 'main'),
    'allowed_repository' => env('GITHUB_DEPLOY_REPOSITORY', ''),

    // Deployment runs in a queued job to return 202 quickly to GitHub.
    'queue' => env('GITHUB_DEPLOY_QUEUE', 'default'),

    // This script runs inside the app container unless overridden.
    'script_path' => env('GITHUB_DEPLOY_SCRIPT_PATH', base_path('scripts/deploy-from-webhook.sh')),
    'trigger_path' => env('GITHUB_DEPLOY_TRIGGER_PATH', storage_path('logs/deploy.trigger')),
    'script_timeout' => (int) env('GITHUB_DEPLOY_SCRIPT_TIMEOUT', 600),

    // Optional shared token used by a host-level deploy endpoint.
    'host_token' => env('GITHUB_DEPLOY_HOST_TOKEN', ''),
];
