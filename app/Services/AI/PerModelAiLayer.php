<?php

namespace App\Services\AI;

use App\Services\Market\Models\ModelSignalDTO;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * PerModelAiLayer
 *
 * Optional AI refinement layer for individual trading models.
 *
 * When enabled for a model, this layer:
 * 1. Builds a model-specific prompt
 * 2. Calls the AI service (LM Studio / Ollama)
 * 3. Adjusts confidence based on market regime + AI agreement
 * 4. Returns enriched decision data
 *
 * If AI is disabled or fails, the system falls back to the model signal
 * with optional confidence adjustment based on market regime only.
 *
 * The AI layer is stateless and has no persistence responsibility.
 */
class PerModelAiLayer
{
    public function __construct(
        private readonly LmStudioClient $lmStudioClient,
        private readonly AiResponseParser $responseParser,
    ) {}

    /**
     * Interpret a model signal through the optional AI layer.
     *
     * If AI is disabled for this model (via config), returns signal with
     * regime-adjusted confidence only.
     *
     * If AI is enabled:
     * - Builds model-specific prompt
     * - Calls LM Studio
     * - Parses response
     * - Adjusts confidence based on regime + AI agreement
     * - Returns enriched decision
     *
     * On AI failure, falls back safely to signal with regime adjustment.
     *
     * @param  ModelSignalDTO  $signal  The signal from a trading model
     * @param  array  $marketRegime  Market context: {market_regime, volatility, ...}
     * @param  string  $executionId  Pipeline execution ID for traceability
     * @return array{
     *   action: string,
     *   confidence: int,
     *   reasoning: string,
     *   agreement: bool,
     *   ai_enabled: bool,
     *   ai_response: array|null,
     * }
     */
    public function interpret(
        ModelSignalDTO $signal,
        array $marketRegime,
        string $executionId = '',
    ): array {
        $modelKey = $signal->model;
        $configKey = "models.{$modelKey}";
        $aiEnabled = (bool) config("{$configKey}.ai_enabled", false);

        // Extract base signal data
        $baseConfidence = $signal->score;
        $baseAction = $signal->action;
        $reasoning = implode(' + ', $signal->reasons);

        // If AI is disabled, return signal with regime adjustment only
        if (! $aiEnabled) {
            $adjustedConfidence = $this->adjustConfidence($baseConfidence, $marketRegime, $baseAction, $modelKey);

            Log::debug('[PerModelAiLayer] AI disabled for model', [
                'execution_id' => $executionId,
                'model' => $modelKey,
                'coin' => $signal->coin,
                'base_confidence' => $baseConfidence,
                'adjusted_confidence' => $adjustedConfidence,
            ]);

            return [
                'action' => $baseAction,
                'confidence' => $adjustedConfidence,
                'reasoning' => $reasoning,
                'agreement' => true,
                'ai_enabled' => false,
                'ai_response' => null,
            ];
        }

        // AI is enabled — build prompt and call AI
        try {
            $prompt = $this->buildModelSpecificPrompt($signal, $marketRegime);

            Log::debug('[PerModelAiLayer] Calling AI', [
                'execution_id' => $executionId,
                'model' => $modelKey,
                'coin' => $signal->coin,
                'prompt_length' => strlen($prompt),
            ]);

            $rawResponse = $this->lmStudioClient->chat([
                [
                    'role' => 'user',
                    'content' => $prompt,
                ],
            ]);

            if ($rawResponse === null) {
                Log::warning('[PerModelAiLayer] AI returned null, falling back', [
                    'execution_id' => $executionId,
                    'model' => $modelKey,
                    'coin' => $signal->coin,
                ]);

                $adjustedConfidence = $this->adjustConfidence($baseConfidence, $marketRegime, $baseAction, $modelKey);

                return [
                    'action' => $baseAction,
                    'confidence' => $adjustedConfidence,
                    'reasoning' => $reasoning,
                    'agreement' => true,
                    'ai_enabled' => true,
                    'ai_response' => null,
                ];
            }

            // Parse AI response
            $aiDecision = $this->responseParser->parse($rawResponse);

            if ($aiDecision === null) {
                Log::warning('[PerModelAiLayer] Failed to parse AI response, falling back', [
                    'execution_id' => $executionId,
                    'model' => $modelKey,
                    'coin' => $signal->coin,
                    'raw_response' => $rawResponse,
                ]);

                $adjustedConfidence = $this->adjustConfidence($baseConfidence, $marketRegime, $baseAction, $modelKey);

                return [
                    'action' => $baseAction,
                    'confidence' => $adjustedConfidence,
                    'reasoning' => $reasoning,
                    'agreement' => true,
                    'ai_enabled' => true,
                    'ai_response' => $rawResponse,
                ];
            }

            // Check if AI agrees with model signal
            $aiAction = $aiDecision['action'] ?? $baseAction;
            $aiConfidence = $aiDecision['confidence'] ?? $baseConfidence;
            $aiReasoning = $aiDecision['reason'] ?? '';
            $agreement = ($aiAction === $baseAction);

            // Adjust confidence based on regime + AI agreement
            $adjustedConfidence = $this->adjustConfidence($aiConfidence, $marketRegime, $aiAction, $modelKey, $agreement);

            Log::info('[PerModelAiLayer] AI decision received', [
                'execution_id' => $executionId,
                'model' => $modelKey,
                'coin' => $signal->coin,
                'base_action' => $baseAction,
                'ai_action' => $aiAction,
                'agreement' => $agreement,
                'base_confidence' => $baseConfidence,
                'ai_confidence' => $aiConfidence,
                'adjusted_confidence' => $adjustedConfidence,
            ]);

            return [
                'action' => $aiAction,
                'confidence' => $adjustedConfidence,
                'reasoning' => $aiReasoning ?: $reasoning,
                'agreement' => $agreement,
                'ai_enabled' => true,
                'ai_response' => $rawResponse,
            ];
        } catch (Throwable $e) {
            Log::error('[PerModelAiLayer] AI call failed, falling back', [
                'execution_id' => $executionId,
                'model' => $modelKey,
                'coin' => $signal->coin,
                'error' => $e->getMessage(),
            ]);

            $adjustedConfidence = $this->adjustConfidence($baseConfidence, $marketRegime, $baseAction, $modelKey);

            return [
                'action' => $baseAction,
                'confidence' => $adjustedConfidence,
                'reasoning' => $reasoning,
                'agreement' => true,
                'ai_enabled' => true,
                'ai_response' => null,
            ];
        }
    }

    /**
     * Build a model-specific prompt for the AI layer.
     *
     * Each model has different strategy logic, so the prompt
     * emphasizes the key components for that model.
     *
     * @return string Fully constructed prompt for LM Studio
     */
    private function buildModelSpecificPrompt(ModelSignalDTO $signal, array $marketRegime): string
    {
        return match ($signal->model) {
            'counter_trend' => $this->buildCounterTrendPrompt($signal, $marketRegime),
            'pre_pump' => $this->buildPrePumpPrompt($signal, $marketRegime),
            'momentum' => $this->buildMomentumPrompt($signal, $marketRegime),
            default => throw new InvalidArgumentException("Unknown model: {$signal->model}"),
        };
    }

    /**
     * Build prompt for Counter-Trend model.
     *
     * Emphasizes liquidity sweep, market structure shift, and divergences.
     * Focus: Is this a legitimate reversal or a false break?
     */
    private function buildCounterTrendPrompt(ModelSignalDTO $signal, array $marketRegime): string
    {
        $scores = $signal->componentScores;
        $regime = $marketRegime['market_regime'] ?? 'RANGING';
        $volatility = $marketRegime['volatility'] ?? 'MEDIUM';
        $btcDirection = $marketRegime['btc_direction'] ?? 'UNKNOWN';
        $riskLevel = $marketRegime['risk_level'] ?? 'MEDIUM';

        $prompt = <<<EOT
You are a crypto reversal trading analyst. Evaluate this Counter-Trend (reversal) setup.

COIN: {$signal->coin} | TIMEFRAME: {$signal->primaryTimeframe}
PROPOSED ACTION: {$signal->action}
MODEL CONFIDENCE: {$signal->score}%

MARKET CONTEXT:
- Regime: {$regime}
- Volatility: {$volatility}
- BTC Direction: {$btcDirection}
- Risk Level: {$riskLevel}

REVERSAL SETUP COMPONENTS:
- Liquidity Sweep: {$this->scorePercent($scores['sweep'] ?? 0)}%
- Market Structure Shift: {$this->scorePercent($scores['mss'] ?? 0)}%
- OI Divergence: {$this->scorePercent($scores['oi'] ?? 0)}%
- CVD Divergence: {$this->scorePercent($scores['cvd'] ?? 0)}%
- Funding Shift: {$this->scorePercent($scores['funding'] ?? 0)}%
- ATR Volatility: {$this->scorePercent($scores['atr'] ?? 0)}%

KEY QUESTION: Is the liquidity sweep likely a legitimate reversal or a false break?

Consider:
1. Is the sweep aligned with ATR volatility compression?
2. Is CVD divergence genuine or noise?
3. How does the market regime affect reversal probability?
4. In {$regime} market, are reversals high probability or rare?

Respond with ONLY valid JSON:
{
    "action": "{$signal->action}",
    "confidence": 0-100,
    "risk_level": "LOW|MEDIUM|HIGH",
    "reason": "brief reasoning"
}
EOT;

        return $prompt;
    }

    /**
     * Build prompt for Pre-Pump model.
     *
     * Emphasizes funding extremes, ATR compression, and OI expansion.
     * Focus: Is the squeeze setup likely to break up or is it a false setup?
     */
    private function buildPrePumpPrompt(ModelSignalDTO $signal, array $marketRegime): string
    {
        $scores = $signal->componentScores;
        $regime = $marketRegime['market_regime'] ?? 'RANGING';
        $volatility = $marketRegime['volatility'] ?? 'MEDIUM';
        $btcDirection = $marketRegime['btc_direction'] ?? 'UNKNOWN';
        $riskLevel = $marketRegime['risk_level'] ?? 'MEDIUM';

        $prompt = <<<EOT
You are a crypto squeeze trading analyst. Evaluate this Pre-Pump (short squeeze) setup.

COIN: {$signal->coin} | TIMEFRAME: {$signal->primaryTimeframe}
PROPOSED ACTION: {$signal->action}
MODEL CONFIDENCE: {$signal->score}%

MARKET CONTEXT:
- Regime: {$regime}
- Volatility: {$volatility}
- BTC Direction: {$btcDirection}
- Risk Level: {$riskLevel}

SQUEEZE SETUP COMPONENTS:
- Funding Extreme: {$this->scorePercent($scores['funding'] ?? 0)}%
- ATR Compression: {$this->scorePercent($scores['atr_compression'] ?? 0)}%
- OI Expansion: {$this->scorePercent($scores['oi'] ?? 0)}%
- Relative Strength: {$this->scorePercent($scores['rs'] ?? 0)}%
- CVD Momentum: {$this->scorePercent($scores['cvd'] ?? 0)}%

KEY QUESTION: Is the squeeze likely to break upward (BUY) or is it a bear trap?

Consider:
1. Is funding negative enough and consistently negative?
2. Is ATR truly compressed relative to baseline?
3. Is OI expanding into the compression zone?
4. How does the market regime affect breakout probability?
5. In {$regime} market, are squeezes high probability breakouts or traps?

Respond with ONLY valid JSON:
{
    "action": "{$signal->action}",
    "confidence": 0-100,
    "risk_level": "LOW|MEDIUM|HIGH",
    "reason": "brief reasoning"
}
EOT;

        return $prompt;
    }

    /**
     * Build prompt for Momentum model.
     *
     * Emphasizes EMA structure, MACD/RSI alignment, and structure breaks.
     * Focus: Is the trend genuine or is it a false break in a choppy market?
     */
    private function buildMomentumPrompt(ModelSignalDTO $signal, array $marketRegime): string
    {
        $scores = $signal->componentScores;
        $regime = $marketRegime['market_regime'] ?? 'RANGING';
        $volatility = $marketRegime['volatility'] ?? 'MEDIUM';
        $btcDirection = $marketRegime['btc_direction'] ?? 'UNKNOWN';
        $riskLevel = $marketRegime['risk_level'] ?? 'MEDIUM';

        $prompt = <<<EOT
You are a crypto trend continuation analyst. Evaluate this Momentum (trend) setup.

COIN: {$signal->coin} | TIMEFRAME: {$signal->primaryTimeframe}
PROPOSED ACTION: {$signal->action}
MODEL CONFIDENCE: {$signal->score}%

MARKET CONTEXT:
- Regime: {$regime}
- Volatility: {$volatility}
- BTC Direction: {$btcDirection}
- Risk Level: {$riskLevel}

TREND COMPONENTS:
- EMA Structure Alignment: {$this->scorePercent($scores['ema'] ?? 0)}%
- MACD Momentum: {$this->scorePercent($scores['macd'] ?? 0)}%
- RSI Momentum: {$this->scorePercent($scores['rsi'] ?? 0)}%
- OI Trend: {$this->scorePercent($scores['oi'] ?? 0)}%
- Break of Structure: {$this->scorePercent($scores['bos'] ?? 0)}%
- CVD Momentum: {$this->scorePercent($scores['cvd'] ?? 0)}%

KEY QUESTION: Is the trend genuine continuation or a fake break in {$regime} market?

Consider:
1. Are EMA slopes aligned with price direction?
2. Is MACD actually in histogram expansion or is it weakening?
3. Is RSI in the correct zone for the proposed action?
4. How does the market regime affect trend probability?
5. In {$regime} market, are trends likely to continue or reverse?

Respond with ONLY valid JSON:
{
    "action": "{$signal->action}",
    "confidence": 0-100,
    "risk_level": "LOW|MEDIUM|HIGH",
    "reason": "brief reasoning"
}
EOT;

        return $prompt;
    }

    /**
     * Adjust confidence based on market regime and AI agreement.
     *
     * Applies model-specific confidence adjusters from config.
     *
     * @param  int  $baseConfidence  Base confidence from signal or AI
     * @param  array  $marketRegime  Market context
     * @param  string  $action  BUY or SELL
     * @param  string  $modelKey  Model identifier (counter_trend, pre_pump, momentum)
     * @param  bool  $aiAgrees  Whether AI agreed with model signal
     * @return int Adjusted confidence (0-100)
     */
    private function adjustConfidence(
        int $baseConfidence,
        array $marketRegime,
        string $action,
        string $modelKey,
        bool $aiAgrees = true,
    ): int {
        $regime = $marketRegime['market_regime'] ?? 'RANGING';
        $adjusterKey = "models.{$modelKey}.market_confidence_adjusters.{$regime}";
        $regimeAdjuster = (int) config($adjusterKey, 0);

        $adjusted = $baseConfidence + $regimeAdjuster;

        // If AI disagreed, reduce confidence further
        if (! $aiAgrees) {
            $adjusted = (int) ($adjusted * 0.75);
        }

        // Clamp to 0-100
        return max(0, min(100, $adjusted));
    }

    /**
     * Convert a decimal score (0-1) to percentage (0-100).
     */
    private function scorePercent(float $score): int
    {
        return (int) round($score * 100);
    }
}
