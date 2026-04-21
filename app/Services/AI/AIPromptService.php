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
        You are a professional crypto trading advisor AI.

        Your task is to decide whether to:

        - BUY
        - SELL
        - HOLD

        Based ONLY on the provided structured market data below. Do not assume any information outside this context.

        ================================================================
        TRADING STRATEGY CONTEXT
        ================================================================

        - Target profit   : 1–2% (short-term scalp)
        - Timeframe       : {$timeframe}
        - Prioritize high-probability, low-risk entries
        - Avoid overtrading
        - If signal is weak or uncertain → return HOLD

        ================================================================
        MARKET DATA
        ================================================================

        Symbol            : {$symbol}
        Market Trend      : {$trend}

        Indicators:
          RSI             : {$rsi}
          EMA Trend       : {$emaTrend}
          MACD Signal     : {$macdSignal}
          Volume Ratio    : {$volumeRatio}

        Current Price     : {$price}

        MCP Suggested Bias: {$actionCandidate}
        MCP Score         : {$score}

        ================================================================
        DECISION RULES
        ================================================================

        1. Follow the dominant trend direction unless a strong reversal signal is present.
        2. RSI < 35  → potential BUY zone.
        3. RSI > 65  → potential SELL zone.
        4. RSI 35–65 → neutral; do not rely on RSI alone.
        5. Volume Ratio > 1.5 confirms signal strength; < 1.0 weakens it.
        6. A MACD crossover in the trend direction strengthens the signal.
        7. MCP Score < 5 → be conservative; prefer HOLD over marginal BUY/SELL.
        8. Do NOT force an action when signals are conflicting.

        ================================================================
        OUTPUT FORMAT (STRICT — DO NOT DEVIATE)
        ================================================================

        Return ONLY the following JSON object. No explanation, no markdown, no extra text.

        {
          "action": "BUY | SELL | HOLD",
          "confidence": 0-100,
          "reason": "short explanation",
          "risk_level": "LOW | MEDIUM | HIGH"
        }

        ================================================================
        CONSTRAINTS
        ================================================================

        - "action"     : must be exactly one of BUY, SELL, HOLD (uppercase)
        - "confidence" : integer from 0 to 100
        - "reason"     : maximum 20 words, plain English, no special characters
        - "risk_level" : must be exactly one of LOW, MEDIUM, HIGH (uppercase)
        - Do NOT include any text outside the JSON object
        - Do NOT wrap the JSON in markdown code fences
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
