<?php

namespace App\Services\AI;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * LmStudioClient
 *
 * Low-level HTTP client responsible for communicating with the local Ollama
 * inference server. It sends a generate request and returns the full raw
 * JSON response without any interpretation.
 *
 * Responsibilities:
 *   - POST to /api/generate
 *   - Combine messages array into a single prompt string
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
        $this->baseUrl = rtrim((string) config('ai.ollama.base_url', 'http://localhost:11434'), '/');
        $this->model = (string) config('ai.ollama.model', 'qwen2.5:7b');
        $this->timeout = (int) config('ai.ollama.timeout', 30);
    }

    /**
     * Send a generate request to Ollama.
     *
     * Accepts an array of messages formatted as:
     *   [['role' => 'system'|'user'|'assistant', 'content' => string], ...]
     *
     * All message contents are concatenated into a single prompt string before
     * being forwarded to Ollama's /api/generate endpoint.
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

        // Combine all message contents into a single prompt string for Ollama.
        $prompt = implode("\n\n", array_filter(
            array_map(static fn (array $m): string => trim((string) ($m['content'] ?? '')), $messages)
        ));

        $payload = [
            'model' => $resolvedModel,
            'prompt' => $prompt,
            'stream' => false,
        ];

        try {
            $response = Http::timeout($this->timeout)
                ->post("{$this->baseUrl}/api/generate", $payload);

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
            Log::error('[LmStudioClient] Connection failed — is Ollama running?', [
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
