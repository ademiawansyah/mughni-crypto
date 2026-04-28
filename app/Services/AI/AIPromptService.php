<?php

namespace App\Services\AI;

use App\Services\Trading\MTFResultDTO;
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
     * }  $mcpData  Structured payload produced by SignalPreFilterService
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
        You are a crypto trading advisor.

        Return ONLY valid single-line JSON. No markdown, no explanation.

        OBJECTIVE:
        - Pick the best action from BUY, SELL, HOLD using the provided context.
        - HOLD only when edge is unclear or risk/reward is poor.
        - Use confidence to express conviction, not as a hard gate.

        GUIDANCE:
        - RSI low + bullish structure can support BUY.
        - RSI high + bearish structure can support SELL.
        - Trend and EMA alignment should influence conviction.
        - Lower volume context should reduce confidence.

        OUTPUT FORMAT (STRICT):
        {"action":"BUY|SELL|HOLD","confidence":number,"risk_level":"LOW|MEDIUM|HIGH","reason":"short reason"}

        CONSTRAINTS:
        - "action" must be BUY, SELL, or HOLD
        - "confidence" must be integer 0-100
        - "risk_level" must be LOW, MEDIUM, or HIGH
        - "reason" max 24 words, plain English
        - No text outside JSON

        INPUT:
        Symbol: {$symbol}
        Timeframe: {$timeframe}
        MCP Action Candidate: {$actionCandidate}
        MCP Score: {$score}
        Trend: {$trend}
        RSI: {$rsi}
        EMA Trend: {$emaTrend}
        MACD Signal: {$macdSignal}
        Volume Ratio: {$volumeRatio}
        Current Price: {$price}

        PROMPT;
    }

    /**
     * Build an AI prompt where MTF is advisory context, not a hard decision gate.
     *
     * @param  array{
     *   symbol: string,
     *   timeframe: string,
     *   action_candidate: string,
     *   score: int,
     *   market_context: array{trend: string},
     *   indicators: array{rsi: float, ema_trend: string, volume_ratio: float},
     *   price: array{current: float},
     * }  $mcpData
     */
    public function buildMtfContextPrompt(array $mcpData, MTFResultDTO $mtfResult, string $timeframeSummary = ''): string
    {
        $this->validateFields($mcpData);

        $symbol = strtoupper(trim($mcpData['symbol']));
        $entryTimeframe = trim($mcpData['timeframe']);
        $actionCandidate = strtoupper(trim($mcpData['action_candidate']));
        $score = (int) $mcpData['score'];
        $trend = strtoupper(trim($mcpData['market_context']['trend']));
        $rsi = round((float) $mcpData['indicators']['rsi'], 2);
        $emaTrend = strtoupper(trim($mcpData['indicators']['ema_trend']));
        $volumeRatio = round((float) $mcpData['indicators']['volume_ratio'], 2);
        $price = round((float) $mcpData['price']['current'], 4);

        return <<<PROMPT
        You are a crypto trading advisor.

        Return ONLY valid single-line JSON. No markdown, no explanation.

        OBJECTIVE:
        - Decide BUY, SELL, or HOLD from the full context.
        - MTF data is contextual and should influence confidence, not force a direction.
        - Do not default to HOLD unless edge is genuinely weak.

        OUTPUT FORMAT (STRICT):
        {"action":"BUY|SELL|HOLD","confidence":number,"risk_level":"LOW|MEDIUM|HIGH","reason":"short reason"}

        CONSTRAINTS:
        - "action" must be BUY, SELL, or HOLD
        - "confidence" must be integer 0-100
        - "risk_level" must be LOW, MEDIUM, or HIGH
        - "reason" max 24 words, plain English
        - No text outside JSON

        ENTRY INPUT:
        Symbol: {$symbol}
        Entry Timeframe: {$entryTimeframe}
        MCP Action Candidate: {$actionCandidate}
        MCP Score: {$score}
        RSI: {$rsi}
        Trend: {$trend}
        EMA Trend: {$emaTrend}
        Volume Ratio: {$volumeRatio}
        Current Price: {$price}

        MTF CONTEXT (ADVISORY):
        MTF Score: {$mtfResult->mtfScore}
        MTF Mode: {$mtfResult->mode}
        Base Confidence Hint: {$mtfResult->baseConfidence}
        Timeframe Summary: {$timeframeSummary}
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

    /**
     * Build a deterministic MTF-aware prompt for AI refinement.
     *
     * The AI receives the deterministic preliminary_action from MTF scoring and is
     * explicitly instructed NOT to change it. Its only job is to refine confidence
     * and provide a short reason.
     *
     * @param  array{
     *   symbol: string,
     *   timeframe: string,
     *   action_candidate: string,
     *   score: int,
     *   market_context: array{trend: string},
     *   indicators: array{rsi: float, ema_trend: string, volume_ratio: float},
     *   price: array{current: float},
     * }  $mcpData  Entry-timeframe MCP payload
     * @param  MTFResultDTO  $mtfResult  Multi-timeframe scoring result
     * @param  string  $timeframeSummary  Human-readable summary of all timeframes
     * @return string Fully constructed MTF prompt ready for the AI endpoint
     *
     * @throws InvalidArgumentException When required fields are missing
     */
    public function buildMtfPrompt(array $mcpData, MTFResultDTO $mtfResult, string $timeframeSummary = ''): string
    {
        $this->validateFields($mcpData);

        $symbol = strtoupper(trim($mcpData['symbol']));
        $entryTimeframe = trim($mcpData['timeframe']);
        $preliminaryAction = strtoupper(trim($mtfResult->preliminaryAction));
        $mtfScore = round($mtfResult->mtfScore, 4);
        $baseConfidence = $mtfResult->baseConfidence;
        $trend = strtoupper(trim($mcpData['market_context']['trend']));
        $rsi = round((float) $mcpData['indicators']['rsi'], 2);
        $volumeRatio = round((float) $mcpData['indicators']['volume_ratio'], 2);

        return $this->renderMtfTemplate(
            symbol: $symbol,
            entryTimeframe: $entryTimeframe,
            preliminaryAction: $preliminaryAction,
            mtfScore: $mtfScore,
            baseConfidence: $baseConfidence,
            trend: $trend,
            rsi: $rsi,
            volumeRatio: $volumeRatio,
            timeframeSummary: $timeframeSummary,
        );
    }

    /**
     * Render the MTF prompt template with injected values.
     *
     * AI role is refinement only — it MUST NOT change the preliminary_action.
     * If preliminary_action is HOLD, action must be HOLD.
     * If confidence < 55, action must be HOLD.
     */
    private function renderMtfTemplate(
        string $symbol,
        string $entryTimeframe,
        string $preliminaryAction,
        float $mtfScore,
        int $baseConfidence,
        string $trend,
        float $rsi,
        float $volumeRatio,
        string $timeframeSummary,
    ): string {
        return <<<PROMPT
        You are a crypto trading signal refinement engine.

        The PRELIMINARY ACTION has been determined by a deterministic Multi-Timeframe (MTF) scoring system.
        Your ONLY job is to refine the confidence level, assign a risk level, and provide a short reason.
        You MUST NOT change the action from the preliminary action.

        Return ONLY valid single-line JSON. No markdown, no explanation.

        ---

        HARD RULES (CANNOT BE BROKEN):

        1. "action" MUST equal the PRELIMINARY_ACTION below — do not change it.
        2. If PRELIMINARY_ACTION is HOLD → action MUST be HOLD.
        3. If your computed confidence < 55 → action MUST be HOLD.
        4. Never invent or hallucinate data.

        ---

        CONFIDENCE RULES:

        * Use BASE_CONFIDENCE as your starting point.
        * Adjust ±10 based on RSI and trend context.
        * Maximum confidence: 85.
        * If confidence < 55 → action MUST be HOLD.

        ---

        RISK LEVEL RULES:

        * confidence 70–100 → LOW
        * confidence 40–69 → MEDIUM
        * confidence 0–39 → HIGH

        ---

        OUTPUT FORMAT (STRICT):
        {"action":"BUY|SELL|HOLD","confidence":number,"risk_level":"LOW|MEDIUM|HIGH","reason":"short reason"}

        CONSTRAINTS:

        * "action" must be BUY, SELL, or HOLD
        * "confidence" must be integer 0–100
        * "risk_level" must be LOW, MEDIUM, or HIGH
        * "reason" max 20 words, plain English
        * No text outside JSON

        ---

        MTF INPUT:
        Symbol: {$symbol}
        Entry Timeframe: {$entryTimeframe}
        PRELIMINARY_ACTION: {$preliminaryAction}
        MTF Score: {$mtfScore}
        Base Confidence: {$baseConfidence}
        Timeframe Summary: {$timeframeSummary}

        ENTRY TIMEFRAME CONTEXT:
        RSI: {$rsi}
        Trend: {$trend}
        Volume Ratio: {$volumeRatio}

        PROMPT;
    }

    /**
     * Build AI refinement prompt for deterministic MTF output.
     *
     * AI is strictly advisory and cannot change the preliminary action.
     */
    public function buildMtfRefinementPrompt(
        MTFResultDTO $mtfResult,
        string $timeframeSummary,
        float $rsi5m,
        string $trend5m,
    ): string {
        $preliminaryAction = strtoupper(trim($mtfResult->preliminaryAction));
        $mode = strtolower(trim($mtfResult->mode));
        $trend = strtoupper(trim($trend5m));

        return <<<PROMPT
        You are an AI confidence refiner for a deterministic multi-timeframe trading engine.

        Return ONLY valid single-line JSON. No markdown, no explanation.

        HARD RULES:
        1. action MUST remain exactly PRELIMINARY_ACTION.
        2. confidence adjustment from BASE_CONFIDENCE must be within +/-10.
        3. Do not change any deterministic score or mode.

        OUTPUT FORMAT:
        {"action":"BUY|SELL|HOLD","confidence":number,"risk_level":"LOW|MEDIUM|HIGH","reason":"short reason"}

        INPUT:
        PRELIMINARY_ACTION: {$preliminaryAction}
        BASE_CONFIDENCE: {$mtfResult->baseConfidence}
        MTF_SCORE: {$mtfResult->mtfScore}
        MODE: {$mode}
        RSI_5M: {$rsi5m}
        TREND_5M: {$trend}
        TIMEFRAME_SUMMARY: {$timeframeSummary}
        PROMPT;
    }
}
