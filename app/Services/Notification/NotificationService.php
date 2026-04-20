<?php

namespace App\Services\Notification;

use Illuminate\Support\Facades\Log;

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
        Log::channel('stack')->info('[NotificationService] Trade signal triggered', [
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

        // TODO: extend here to send to Slack, Telegram, email, etc.
    }
}
