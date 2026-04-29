<?php

namespace App\Services\Notification;

use App\Services\Market\Models\ModelSignalDTO;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Laravel\Facades\Telegram;
use Throwable;

/**
 * PerModelNotificationService
 *
 * Formats and sends per-model trading signal notifications to Telegram.
 *
 * Each model (Counter-Trend, Pre-Pump, Momentum) has distinct notification formatting
 * to clearly label the signal source and provide model-specific context.
 *
 * Notifications are sent ONLY when:
 * - Action is BUY or SELL (not HOLD)
 * - Confidence meets threshold (per model)
 * - Signal was persisted (not a duplicate)
 */
class PerModelNotificationService
{
    /**
     * Send a per-model trading signal notification to Telegram.
     *
     * @param  string  $model  Model identifier: counter_trend, pre_pump, momentum
     * @param  ModelSignalDTO  $signal  The signal from the model
     * @param  array  $aiDecision  AI layer result: {action, confidence, reasoning, agreement, ai_enabled}
     * @param  array  $marketRegime  Market context: {market_regime, volatility, risk_level, ...}
     * @param  string  $executionId  Pipeline execution ID for traceability
     */
    public function notify(
        string $model,
        ModelSignalDTO $signal,
        array $aiDecision,
        array $marketRegime,
        string $executionId = '',
    ): void {
        try {
            $message = $this->buildMessage($model, $signal, $aiDecision, $marketRegime, $executionId);

            $chatId = (string) config('services.telegram.chat_id', '');
            $botName = (string) config('services.telegram.bot', 'mybot');

            if ($chatId === '') {
                Log::warning('[PerModelNotificationService] Telegram chat ID is not configured, skipping send', [
                    'execution_id' => $executionId,
                    'model' => $model,
                    'coin' => $signal->coin,
                ]);

                return;
            }

            Telegram::bot($botName)->sendMessage([
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => 'HTML',
            ]);

            Log::info('[PerModelNotificationService] Notification sent', [
                'execution_id' => $executionId,
                'model' => $model,
                'coin' => $signal->coin,
                'action' => $aiDecision['action'],
                'confidence' => $aiDecision['confidence'],
            ]);
        } catch (Throwable $e) {
            Log::error('[PerModelNotificationService] Failed to send notification', [
                'execution_id' => $executionId,
                'model' => $model,
                'coin' => $signal->coin,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Build a formatted notification message for the model signal.
     *
     * Format includes:
     * - Model and signal type (counter_trend, pre_pump, momentum)
     * - Coin, timeframe, and action (BUY/SELL)
     * - Confidence level
     * - Setup reasoning (model-specific)
     * - Market regime context and risk assessment
     * - AI status (enabled/disabled, agreement)
     *
     * @return string HTML-formatted message
     */
    private function buildMessage(
        string $model,
        ModelSignalDTO $signal,
        array $aiDecision,
        array $marketRegime,
        string $executionId,
    ): string {
        $emoji = $this->getModelEmoji($model);
        $modelLabel = $this->formatModelLabel($model);
        $actionColor = $aiDecision['action'] === 'BUY' ? '🟢' : '🔴';
        $regime = $marketRegime['market_regime'] ?? 'UNKNOWN';
        $risk = $marketRegime['risk_level'] ?? 'MEDIUM';

        // Build component context (model-specific)
        $componentContext = $this->formatComponentContext($model, $signal);

        // Build AI status line
        $aiStatus = $this->formatAiStatus($aiDecision);

        // Format confidence as a visual bar
        $confidence = $aiDecision['confidence'];
        $confidenceBar = $this->buildConfidenceBar($confidence);

        return <<<EOT
$emoji <b>{$modelLabel}</b> — {$actionColor} <b>{$aiDecision['action']}</b> Signal

<b>📊 {$signal->coin}</b> | {$signal->primaryTimeframe}

{$confidenceBar}

<b>Setup:</b> {$componentContext}

<b>Market:</b> <i>{$regime}</i> (Risk: {$risk})
{$aiStatus}
<i>Execution ID: {$this->executionIdShort($executionId)}</i>
EOT;
    }

    /**
     * Get emoji for model type.
     */
    private function getModelEmoji(string $model): string
    {
        return match ($model) {
            'counter_trend' => '🔄',
            'pre_pump' => '💥',
            'momentum' => '🚀',
            default => '📈',
        };
    }

    /**
     * Format model label with human-readable name.
     */
    private function formatModelLabel(string $model): string
    {
        return match ($model) {
            'counter_trend' => 'COUNTER-TREND MODEL',
            'pre_pump' => 'PRE-PUMP MODEL',
            'momentum' => 'MOMENTUM MODEL',
            default => strtoupper($model),
        };
    }

    /**
     * Format component context based on model type.
     *
     * Shows the top 2-3 contributing factors for this model's signal.
     */
    private function formatComponentContext(string $model, ModelSignalDTO $signal): string
    {
        $reasons = implode(' + ', array_slice($signal->reasons, 0, 3));

        return match ($model) {
            'counter_trend' => sprintf(
                'Liquidity sweep (%d%%) + MSS + OI divergence',
                (int) round(((float) ($signal->componentScores['sweep'] ?? 0)) * 100)
            ),
            'pre_pump' => sprintf(
                'Funding extreme (%d%%) + ATR compression + OI expansion',
                (int) round(((float) ($signal->componentScores['funding'] ?? 0)) * 100)
            ),
            'momentum' => sprintf(
                'EMA aligned (%d%%) + MACD bullish + RSI zone',
                (int) round(((float) ($signal->componentScores['ema'] ?? 0)) * 100)
            ),
            default => $reasons,
        };
    }

    /**
     * Format AI status line.
     *
     * Shows whether AI refined the signal and if it agreed with model.
     */
    private function formatAiStatus(array $aiDecision): string
    {
        if (! $aiDecision['ai_enabled']) {
            return '<i>Model only (AI disabled)</i>';
        }

        $agreement = $aiDecision['agreement'] ? '✅ Agreed' : '⚠️ Disagreed';

        return "<i>AI Refinement: {$agreement}</i>";
    }

    /**
     * Build a visual confidence level bar.
     *
     * Examples:
     * - 80% → [████████░░] 80%
     * - 55% → [█████░░░░░] 55%
     * - 35% → [███░░░░░░░] 35%
     */
    private function buildConfidenceBar(int $confidence): string
    {
        $filled = (int) round($confidence / 10);
        $empty = 10 - $filled;

        $bar = str_repeat('█', $filled).str_repeat('░', $empty);

        return "<b>Confidence:</b> [{$bar}] {$confidence}%";
    }

    /**
     * Get short execution ID suffix for message footer.
     */
    private function executionIdShort(string $executionId): string
    {
        if ($executionId === '') {
            return 'n/a';
        }

        return substr($executionId, 0, 8);
    }
}
