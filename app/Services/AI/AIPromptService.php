<?php

namespace App\Services\AI;

use InvalidArgumentException;

/**
 * AIPromptService
 *
 * Converts a structured MCP output array into a deterministic, instruction-first
 * prompt string ready to be sent to LM Studio (or any OpenAI-compatible endpoint).
 *
 * Responsibilities:
 *   - Validate required MCP fields before building the prompt
 *   - Normalize numeric values for consistent AI interpretation
 *   - Inject all MCP context into a fixed template (no ad-hoc concatenation)
 *   - Enforce strict JSON-only output rules inside the prompt itself
 *
 * This service is stateless and has no external dependencies.
 */
class AIPromptService
{
    /**
     * Required top-level keys that must be present in the MCP data array.
     *
     * @var list<string>
     */
    private const REQUIRED_FIELDS = [
        'symbol',
        'timeframe',
        'action_candidate',
        'score',
        'market_context',
        'indicators',
        'price',
    ];

    /**
     * Required keys inside the `indicators` sub-array.
     *
     * @var list<string>
     */
    private const REQUIRED_INDICATOR_FIELDS = ['rsi', 'ema_trend', 'volume_ratio'];

    /**
     * Build a deterministic prompt string from structured MCP output.
     *
     * Validates all required fields, normalises numeric values, and injects
     * every data point into a fixed template. The resulting string enforces
     * JSON-only output on the AI side.
     *
     * @param  array{
     *   symbol: string,
     *   timeframe: string,
     *   action_candidate: string,
     *   score: int,
     *   market_context: array{trend: string},
     *   indicators: array{rsi: float, ema_trend: string, macd_signal?: string, volume_ratio: float},
     *   price: array{current: float},
     * }  $mcpData  Structured payload produced by MCPService
     * @return string Fully constructed prompt ready for LM Studio
     *
     * @throws InvalidArgumentException When required fields are missing or malformed
     */
    public function buildPrompt(array $mcpData): string
    {
        $this->validateFields($mcpData);

        // Extract and normalise all values before injecting into the template.
        $symbol = strtoupper(trim($mcpData['symbol']));
        $timeframe = trim($mcpData['timeframe']);
        $actionCandidate = strtoupper(trim($mcpData['action_candidate']));
        $score = (int) $mcpData['score'];
        $trend = strtoupper(trim($mcpData['market_context']['trend']));

        $rsi = round((float) $mcpData['indicators']['rsi'], 2);
        $emaTrend = strtoupper(trim($mcpData['indicators']['ema_trend']));
        $macdSignal = strtoupper(trim($mcpData['indicators']['macd_signal'] ?? 'N/A'));
        $volumeRatio = round((float) $mcpData['indicators']['volume_ratio'], 2);
        $price = round((float) $mcpData['price']['current'], 4);

        return $this->renderTemplate(
            symbol: $symbol,
            timeframe: $timeframe,
            actionCandidate: $actionCandidate,
            score: $score,
            trend: $trend,
            rsi: $rsi,
            emaTrend: $emaTrend,
            macdSignal: $macdSignal,
            volumeRatio: $volumeRatio,
            price: $price,
        );
    }

    /**
     * Render the fixed prompt template with all injected values.
     *
     * All variables are isolated via named parameters to prevent accidental
     * mis-ordering or omission. The template itself is never modified —
     * only placeholder substitution occurs here.
     */
    private function renderTemplate(
        string $symbol,
        string $timeframe,
        string $actionCandidate,
        int $score,
        string $trend,
        float $rsi,
        string $emaTrend,
        string $macdSignal,
        float $volumeRatio,
        float $price,
    ): string {
        return <<<PROMPT
        You are a strict crypto trading signal engine.

        You must return ONLY valid JSON. No markdown, no code block, no explanation.

        Rules:

        * Default action is HOLD
        * Only return BUY or SELL if signal is strong
        * Be conservative and avoid overtrading
        * Output must be a single-line JSON

        Signal logic:

        * RSI < 25 → potential BUY
        * RSI > 75 → potential SELL
        * Trend DOWN + RSI < 25 → strong BUY
        * Trend UP + RSI > 75 → strong SELL
        * Low volume → reduce confidence

        Confidence rules:

        * Strong signal → 70–85
        * Medium → 55–70
        * Weak → below 55 → must be HOLD

        Risk level rules:

        * confidence 70–100 → risk_level LOW
        * confidence 40–69  → risk_level MEDIUM
        * confidence 0–39   → risk_level HIGH

        Output format (strict):
        {"action":"BUY|SELL|HOLD","confidence":number,"risk_level":"LOW|MEDIUM|HIGH","reason":"short reason"}

        Constraints:
        - "action"     : must be exactly one of BUY, SELL, HOLD (uppercase)
        - "confidence" : integer from 0 to 100
        - "risk_level" : must be exactly one of LOW, MEDIUM, HIGH (uppercase)
        - "reason"     : maximum 20 words, plain English, no special characters
        - Do NOT include any text outside the JSON object
        - Do NOT wrap the JSON in markdown code fences

        Input:
        RSI: {$rsi}
        Trend: {$trend}
        Volume: {$volumeRatio}
        Price Change 24h: N/A
        PROMPT;
    }

    /**
     * Assert that all required fields are present and structurally valid.
     *
     * @param  array<string, mixed>  $mcpData
     *
     * @throws InvalidArgumentException
     */
    private function validateFields(array $mcpData): void
    {
        foreach (self::REQUIRED_FIELDS as $field) {
            if (! array_key_exists($field, $mcpData)) {
                throw new InvalidArgumentException(
                    "[AIPromptService] Missing required MCP field: '{$field}'"
                );
            }
        }

        if (! is_array($mcpData['market_context']) || ! isset($mcpData['market_context']['trend'])) {
            throw new InvalidArgumentException(
                "[AIPromptService] 'market_context.trend' is missing or invalid"
            );
        }

        if (! is_array($mcpData['indicators'])) {
            throw new InvalidArgumentException(
                "[AIPromptService] 'indicators' must be an array"
            );
        }

        foreach (self::REQUIRED_INDICATOR_FIELDS as $field) {
            if (! array_key_exists($field, $mcpData['indicators'])) {
                throw new InvalidArgumentException(
                    "[AIPromptService] Missing required indicator field: '{$field}'"
                );
            }
        }

        if (! is_array($mcpData['price']) || ! isset($mcpData['price']['current'])) {
            throw new InvalidArgumentException(
                "[AIPromptService] 'price.current' is missing or invalid"
            );
        }
    }
}
