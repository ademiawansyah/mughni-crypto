<?php

namespace App\Services\Notification;

use Illuminate\Support\Facades\Log;
use Telegram\Bot\Laravel\Facades\Telegram;
use Throwable;

/**
 * NotificationService
 *
 * Responsible for dispatching trade signal notifications to configured channels.
 *
 * This service has no business logic — it only formats and sends notifications.
 * Callers must pre-filter decisions before invoking this service.
 *
 * Currently logs the notification. Extend this class to support Slack, Telegram,
 * email, or any other channel without changing the caller.
 */
class NotificationService
{
    /**
     * Send a trade signal notification for a strong BUY or SELL decision.
     *
     * Expects a decision array with keys: coin, timeframe, action, confidence,
     * reason, risk_level, entry (optional), take_profit (optional), stop_loss (optional).
     *
     * @param  array{
     *   execution_id?: string,
     *   coin: string,
     *   timeframe: string,
     *   action: string,
     *   confidence: int,
     *   reason: string,
     *   risk_level: string,
     *   entry?: float|null,
     *   take_profit?: float|null,
     *   stop_loss?: float|null,
     * }  $payload
     */
    public function sendTradeSignal(array $payload): void
    {
        $message = $this->buildTradeSignalMessage($payload);

        Log::info('[NotificationService] Trade signal triggered', [
            'execution_id' => $payload['execution_id'] ?? null,
            'coin' => $payload['coin'],
            'timeframe' => $payload['timeframe'],
            'action' => $payload['action'],
            'confidence' => $payload['confidence'],
            'risk_level' => $payload['risk_level'],
            'entry' => $payload['entry'] ?? null,
            'take_profit' => $payload['take_profit'] ?? null,
            'stop_loss' => $payload['stop_loss'] ?? null,
            'reason' => $payload['reason'],
        ]);

        $this->sendTelegramMessage(
            message: $message,
            executionId: $payload['execution_id'] ?? null,
        );
    }

    /**
     * Build a human-readable trade signal message for Telegram.
     *
     * @param  array{
     *   execution_id?: string,
     *   coin: string,
     *   timeframe: string,
     *   action: string,
     *   confidence: int,
     *   reason: string,
     *   risk_level: string,
     *   entry?: float|null,
     *   take_profit?: float|null,
     *   stop_loss?: float|null,
     * }  $payload
     */
    private function buildTradeSignalMessage(array $payload): string
    {
        $entry = $this->formatDecimal($payload['entry'] ?? null);
        $takeProfit = $this->formatDecimal($payload['take_profit'] ?? null);
        $stopLoss = $this->formatDecimal($payload['stop_loss'] ?? null);
        $executionId = $payload['execution_id'] ?? '-';

        return implode(PHP_EOL, [
            '<b>AI Trading Signal</b>',
            sprintf('Coin: <b>%s</b>', $this->escapeForHtml((string) $payload['coin'])),
            sprintf('Timeframe: <b>%s</b>', $this->escapeForHtml((string) $payload['timeframe'])),
            sprintf('Action: <b>%s</b>', $this->escapeForHtml((string) $payload['action'])),
            sprintf('Confidence: <b>%d%%</b>', (int) $payload['confidence']),
            sprintf('Risk: <b>%s</b>', $this->escapeForHtml((string) $payload['risk_level'])),
            sprintf('Entry: <b>%s</b>', $entry ?? '-'),
            sprintf('TP: <b>%s</b>', $takeProfit ?? '-'),
            sprintf('SL: <b>%s</b>', $stopLoss ?? '-'),
            sprintf('Reason: %s', $this->escapeForHtml((string) $payload['reason'])),
            sprintf('Execution ID: <code>%s</code>', $this->escapeForHtml((string) $executionId)),
        ]);
    }

    /**
     * Send a prepared message to Telegram when bot token and chat id are configured.
     */
    private function sendTelegramMessage(string $message, ?string $executionId): void
    {
        $chatId = (string) config('services.telegram.chat_id', '');

        if ($chatId === '') {
            Log::warning('[NotificationService] Telegram chat ID is not configured, skipping send', [
                'execution_id' => $executionId,
            ]);

            return;
        }

        $botName = (string) config('services.telegram.bot', 'mybot');

        try {
            Telegram::bot($botName)->sendMessage([
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ]);

            Log::info('[NotificationService] Telegram message sent', [
                'execution_id' => $executionId,
                'bot' => $botName,
                'chat_id' => $chatId,
            ]);
        } catch (Throwable $exception) {
            Log::error('[NotificationService] Telegram send failed', [
                'execution_id' => $executionId,
                'bot' => $botName,
                'chat_id' => $chatId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Format optional numeric values with a stable decimal representation.
     */
    private function formatDecimal(mixed $value): ?string
    {
        if (! is_numeric($value)) {
            return null;
        }

        return number_format((float) $value, 8, '.', '');
    }

    /**
     * Escape dynamic values to keep Telegram HTML parse mode safe.
     */
    private function escapeForHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
