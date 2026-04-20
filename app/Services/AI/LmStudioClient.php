<?php

namespace App\Services\AI;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * LmStudioClient
 *
 * Low-level HTTP client responsible for communicating with the local LM Studio
 * inference server. It sends a chat completion request and returns the full raw
 * JSON response without any interpretation.
 *
 * Responsibilities:
 *   - POST to /v1/chat/completions
 *   - Apply configured timeout
 *   - Log failures
 *   - Return null on any failure so callers can react gracefully
 *
 * No prompt construction, response parsing, or business logic belongs here.
 */
class LmStudioClient
{
    private string $baseUrl;

    private string $model;

    private int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('ai.lm_studio.base_url', 'http://127.0.0.1:1234'), '/');
        $this->model = (string) config('ai.lm_studio.model', 'qwen3.5-2b');
        $this->timeout = (int) config('ai.lm_studio.timeout', 30);
    }

    /**
     * Send a chat completion request to LM Studio.
     *
     * Accepts a model override and an array of messages formatted as:
     *   [['role' => 'system'|'user'|'assistant', 'content' => string], ...]
     *
     * Returns the full raw decoded JSON response array, or null on failure.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  string|null  $model  Optional model override; falls back to config value.
     * @return array<string, mixed>|null
     */
    public function chat(array $messages, ?string $model = null): ?array
    {
        $resolvedModel = $model ?? $this->model;

        $payload = [
            'model' => $resolvedModel,
            'messages' => $messages,
        ];

        try {
            $response = Http::timeout($this->timeout)
                ->post("{$this->baseUrl}/v1/chat/completions", $payload);

            if ($response->failed()) {
                Log::error('[LmStudioClient] HTTP request failed', [
                    'status' => $response->status(),
                    'model' => $resolvedModel,
                    'body' => $response->body(),
                ]);

                return null;
            }

            return $response->json();
        } catch (ConnectionException $e) {
            Log::error('[LmStudioClient] Connection failed — is LM Studio running?', [
                'model' => $resolvedModel,
                'exception' => $e->getMessage(),
            ]);

            return null;
        } catch (Throwable $e) {
            Log::error('[LmStudioClient] Unexpected error during request', [
                'model' => $resolvedModel,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }
}
