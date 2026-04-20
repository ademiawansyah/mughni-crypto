<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Log;

/**
 * AiResponseParser
 *
 * Normalizes the raw LM Studio API response into a structured decision array.
 *
 * Parsing strategy (applied in order):
 *   1. Extract the assistant message content from the LM Studio envelope.
 *   2. Attempt to decode the content as JSON directly.
 *   3. If that fails, scan the text for the first JSON object using a regex.
 *   4. If still no valid JSON, extract the action keyword (BUY/SELL/HOLD) from text
 *      and apply fallback values for all other fields.
 *   5. Validate the parsed result; replace any out-of-range or invalid field with
 *      safe defaults so the decision is always usable.
 *
 * Output shape:
 *   [
 *     'action'     => 'BUY'|'SELL'|'HOLD',
 *     'confidence' => int (0–100),
 *     'risk_level' => 'LOW'|'MEDIUM'|'HIGH',
 *     'reason'     => string,
 *   ]
 */
class AiResponseParser
{
    /** @var array<string> */
    private const VALID_ACTIONS = ['BUY', 'SELL', 'HOLD'];

    /** @var array<string> */
    private const VALID_RISK_LEVELS = ['LOW', 'MEDIUM', 'HIGH'];

    private const DEFAULT_ACTION = 'HOLD';

    private const DEFAULT_CONFIDENCE = 50;

    private const DEFAULT_RISK_LEVEL = 'MEDIUM';

    private const DEFAULT_REASON = 'fallback parsing';

    /**
     * Parse a raw LM Studio chat completion response into a structured decision.
     *
     * Accepts the full decoded JSON response array from LM Studio.
     * Returns a normalized decision array regardless of the AI output quality.
     *
     * @param  array<string, mixed>  $rawResponse
     * @return array{action: string, confidence: int, risk_level: string, reason: string}
     */
    public function parse(array $rawResponse): array
    {
        $content = $this->extractContent($rawResponse);

        if ($content === null) {
            Log::warning('[AiResponseParser] No assistant message content found in response.');

            return $this->fallback();
        }

        // Log::debug('[AiResponseParser] Raw AI content', ['content' => $content]);

        // Step 1: direct JSON decode
        $parsed = $this->tryDirectJsonDecode($content);

        // Step 2: extract embedded JSON object via regex
        if ($parsed === null) {
            $parsed = $this->tryExtractJsonFromText($content);
        }

        // Step 3: keyword extraction with full fallback
        if ($parsed === null) {
            $parsed = $this->tryKeywordExtraction($content);
        }

        return $this->validate($parsed);
    }

    /**
     * Pull the assistant's text content out of the LM Studio response envelope.
     *
     * @param  array<string, mixed>  $rawResponse
     */
    private function extractContent(array $rawResponse): ?string
    {
        $content = $rawResponse['choices'][0]['message']['content'] ?? null;

        if (! is_string($content) || trim($content) === '') {
            return null;
        }

        return trim($content);
    }

    /**
     * Attempt to JSON-decode the content string directly.
     *
     * @return array<string, mixed>|null
     */
    private function tryDirectJsonDecode(string $content): ?array
    {
        // Strip common AI preamble such as <think>...</think> blocks before decoding
        $stripped = preg_replace('/<think>.*?<\/think>/si', '', $content);
        $stripped = trim($stripped ?? $content);

        $decoded = json_decode($stripped, true);

        if (is_array($decoded) && $this->hasRequiredKeys($decoded)) {
            return $decoded;
        }

        return null;
    }

    /**
     * Scan free-form text for the first JSON object and attempt to decode it.
     *
     * @return array<string, mixed>|null
     */
    private function tryExtractJsonFromText(string $content): ?array
    {
        if (! preg_match('/\{[^{}]*\}/s', $content, $matches)) {
            return null;
        }

        $decoded = json_decode($matches[0], true);

        if (is_array($decoded) && $this->hasRequiredKeys($decoded)) {
            return $decoded;
        }

        return null;
    }

    /**
     * Extract an action keyword from plain text and apply fallback for all other fields.
     *
     * @return array{action: string, confidence: int, risk_level: string, reason: string}
     */
    private function tryKeywordExtraction(string $content): array
    {
        $upper = strtoupper($content);
        $action = self::DEFAULT_ACTION;

        foreach (self::VALID_ACTIONS as $candidate) {
            if (str_contains($upper, $candidate)) {
                $action = $candidate;
                break;
            }
        }

        return [
            'action' => $action,
            'confidence' => self::DEFAULT_CONFIDENCE,
            'risk_level' => self::DEFAULT_RISK_LEVEL,
            'reason' => self::DEFAULT_REASON,
        ];
    }

    /**
     * Validate every field of a parsed decision and replace invalid values with safe defaults.
     *
     * @param  array<string, mixed>  $parsed
     * @return array{action: string, confidence: int, risk_level: string, reason: string}
     */
    private function validate(array $parsed): array
    {
        $action = strtoupper((string) ($parsed['action'] ?? ''));
        if (! in_array($action, self::VALID_ACTIONS, true)) {
            Log::warning('[AiResponseParser] Invalid action, defaulting to HOLD', ['action' => $action]);
            $action = self::DEFAULT_ACTION;
        }

        $confidence = (int) ($parsed['confidence'] ?? self::DEFAULT_CONFIDENCE);
        if ($confidence < 0 || $confidence > 100) {
            Log::warning('[AiResponseParser] Confidence out of range, defaulting to 50', ['confidence' => $confidence]);
            $confidence = self::DEFAULT_CONFIDENCE;
        }

        $riskLevel = strtoupper((string) ($parsed['risk_level'] ?? ''));
        if (! in_array($riskLevel, self::VALID_RISK_LEVELS, true)) {
            Log::warning('[AiResponseParser] Invalid risk_level, defaulting to MEDIUM', ['risk_level' => $riskLevel]);
            $riskLevel = self::DEFAULT_RISK_LEVEL;
        }

        $reason = trim((string) ($parsed['reason'] ?? self::DEFAULT_REASON));
        if ($reason === '') {
            $reason = self::DEFAULT_REASON;
        }

        return [
            'action' => $action,
            'confidence' => $confidence,
            'risk_level' => $riskLevel,
            'reason' => $reason,
        ];
    }

    /**
     * Return a safe fallback decision used when parsing fails entirely.
     *
     * @return array{action: string, confidence: int, risk_level: string, reason: string}
     */
    private function fallback(): array
    {
        return [
            'action' => self::DEFAULT_ACTION,
            'confidence' => self::DEFAULT_CONFIDENCE,
            'risk_level' => self::DEFAULT_RISK_LEVEL,
            'reason' => self::DEFAULT_REASON,
        ];
    }

    /**
     * Check whether a decoded array contains all keys required by the decision schema.
     *
     * @param  array<string, mixed>  $data
     */
    private function hasRequiredKeys(array $data): bool
    {
        return isset($data['action'], $data['confidence'], $data['risk_level'], $data['reason']);
    }
}
